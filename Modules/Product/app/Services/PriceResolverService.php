<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Core\Helpers\MerchantContext;
use Modules\Product\Models\ProductVariantUnit;

class PriceResolverService
{
    /**
     * Resolve a unit price by branch, price tier, and quantity.
     * For pricing_mode=margin, product_prices.price stores a markup percentage.
     */
    public function resolve(int $variantUnitId, float $quantity, ?int $priceListId = null, ?int $branchId = null): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');
        $unit = ProductVariantUnit::query()->where('merchant_id', $merchantId)->findOrFail($variantUnitId);

        return $this->resolveForUnit($unit, $quantity, $priceListId, $branchId);
    }

    /**
     * Resolve price for an already-loaded ProductVariantUnit model.
     *
     * Priority order:
     * 1. Explicit branch + price list override: [branchId, priceListId]
     * 2. Explicit global + price list override: [null, priceListId]
     * 3. Price list default_markup_percent applied to base price ([branchId, null] or default_price)
     * 4. Explicit branch override: [branchId, null]
     * 5. Unit default_price
     */
    public function resolveForUnit(
        ProductVariantUnit $unit,
        float $quantity,
        ?int $priceListId = null,
        ?int $branchId = null,
        bool $skipScopeValidation = false,
    ): array {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        if (! $skipScopeValidation) {
            $this->validateScopes($merchantId, $priceListId, $branchId);
        }

        $priceListScopes = $this->buildPriceListScopes($branchId, $priceListId);
        foreach ($priceListScopes as [$scopeBranch, $scopeList]) {
            $price = $this->queryScopePrice($merchantId, (int) $unit->id, $quantity, $scopeBranch, $scopeList);
            if ($price) {
                return $this->formatResolvedPrice($unit, $price);
            }
        }

        $branchBaseMatch = $branchId !== null
            ? $this->queryScopePrice($merchantId, (int) $unit->id, $quantity, $branchId, null)
            : null;

        $markupRule = $this->resolvePriceListMarkupRule($merchantId, $priceListId);
        if ($priceListId !== null && $markupRule !== null) {
            return $this->formatMarkupPrice($unit, $branchBaseMatch, $priceListId, $markupRule);
        }

        return $this->formatResolvedPrice($unit, $branchBaseMatch);
    }

    /**
     * Batch-resolve unit prices for a collection of ProductVariantUnit models at a uniform quantity.
     * Eliminates N+1 queries on POS catalog listings.
     *
     * @param  iterable<int, ProductVariantUnit>  $units
     * @return array<int, array<string, mixed>>
     */
    public function resolveMany(
        iterable $units,
        float $quantity,
        ?int $priceListId = null,
        ?int $branchId = null,
        bool $skipScopeValidation = false,
    ): array {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $unitList = collect($units)
            ->filter(fn ($u) => $u instanceof ProductVariantUnit)
            ->values();

        if ($unitList->isEmpty()) {
            return [];
        }

        if (! $skipScopeValidation) {
            $this->validateScopes($merchantId, $priceListId, $branchId);
        }

        $unitIds = $unitList->pluck('id')->map(fn ($id) => (int) $id)->unique()->all();
        $priceListScopes = $this->buildPriceListScopes($branchId, $priceListId);
        $allScopes = $priceListScopes;
        if ($branchId !== null) {
            $allScopes[] = [$branchId, null];
        }

        $pricesByUnit = collect();
        if (! empty($allScopes)) {
            $pricesByUnit = DB::table('product_prices')
                ->where('merchant_id', $merchantId)
                ->whereIn('product_variant_unit_id', $unitIds)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where('min_qty', '<=', $quantity)
                ->where(function ($q) use ($allScopes) {
                    foreach ($allScopes as [$scopeBranch, $scopeList]) {
                        $q->orWhere(function ($sub) use ($scopeBranch, $scopeList) {
                            $scopeBranch === null ? $sub->whereNull('branch_id') : $sub->where('branch_id', $scopeBranch);
                            $scopeList === null ? $sub->whereNull('price_list_id') : $sub->where('price_list_id', $scopeList);
                        });
                    }
                })
                ->orderByDesc('min_qty')
                ->orderByDesc('id')
                ->get()
                ->groupBy('product_variant_unit_id');
        }

        $markupRule = $this->resolvePriceListMarkupRule($merchantId, $priceListId);

        $results = [];
        foreach ($unitList as $unit) {
            $unitId = (int) $unit->id;
            $candidates = $pricesByUnit->get($unitId, collect());
            $matched = $this->findFirstScopeMatch($candidates, $priceListScopes);

            if ($matched) {
                $results[$unitId] = $this->formatResolvedPrice($unit, $matched);
                continue;
            }

            $branchBaseMatch = $branchId !== null
                ? $this->findFirstScopeMatch($candidates, [[$branchId, null]])
                : null;

            if ($priceListId !== null && $markupRule !== null) {
                $results[$unitId] = $this->formatMarkupPrice($unit, $branchBaseMatch, $priceListId, $markupRule);
                continue;
            }

            $results[$unitId] = $this->formatResolvedPrice($unit, $branchBaseMatch);
        }

        return $results;
    }

    /**
     * Batch-resolve unit prices for multiple lines with potentially different quantities per line.
     * Eliminates N+1 product_prices queries during POS checkout.
     *
     * @param  array<int, array{unit: ProductVariantUnit, quantity: float}>  $lines
     * @return array<int, array<string, mixed>>
     */
    public function resolveManyForLines(
        array $lines,
        ?int $priceListId = null,
        ?int $branchId = null,
        bool $skipScopeValidation = false,
    ): array {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        if (empty($lines)) {
            return [];
        }

        if (! $skipScopeValidation) {
            $this->validateScopes($merchantId, $priceListId, $branchId);
        }

        $unitIds = collect($lines)
            ->map(fn (array $entry) => (int) $entry['unit']->id)
            ->unique()
            ->values()
            ->all();

        $priceListScopes = $this->buildPriceListScopes($branchId, $priceListId);
        $allScopes = $priceListScopes;
        if ($branchId !== null) {
            $allScopes[] = [$branchId, null];
        }

        $pricesByUnit = collect();
        if (! empty($allScopes) && ! empty($unitIds)) {
            $pricesByUnit = DB::table('product_prices')
                ->where('merchant_id', $merchantId)
                ->whereIn('product_variant_unit_id', $unitIds)
                ->where('is_active', true)
                ->whereNull('deleted_at')
                ->where(function ($q) use ($allScopes) {
                    foreach ($allScopes as [$scopeBranch, $scopeList]) {
                        $q->orWhere(function ($sub) use ($scopeBranch, $scopeList) {
                            $scopeBranch === null ? $sub->whereNull('branch_id') : $sub->where('branch_id', $scopeBranch);
                            $scopeList === null ? $sub->whereNull('price_list_id') : $sub->where('price_list_id', $scopeList);
                        });
                    }
                })
                ->orderByDesc('min_qty')
                ->orderByDesc('id')
                ->get()
                ->groupBy('product_variant_unit_id');
        }

        $markupRule = $this->resolvePriceListMarkupRule($merchantId, $priceListId);

        $results = [];
        foreach ($lines as $index => $entry) {
            /** @var ProductVariantUnit $unit */
            $unit = $entry['unit'];
            $qty = (float) $entry['quantity'];
            $candidates = $pricesByUnit->get((int) $unit->id, collect());

            $matched = $this->findFirstScopeMatch($candidates, $priceListScopes, $qty);
            if ($matched) {
                $results[$index] = $this->formatResolvedPrice($unit, $matched);
                continue;
            }

            $branchBaseMatch = $branchId !== null
                ? $this->findFirstScopeMatch($candidates, [[$branchId, null]], $qty)
                : null;

            if ($priceListId !== null && $markupRule !== null) {
                $results[$index] = $this->formatMarkupPrice($unit, $branchBaseMatch, $priceListId, $markupRule);
                continue;
            }

            $results[$index] = $this->formatResolvedPrice($unit, $branchBaseMatch);
        }

        return $results;
    }

    /**
     * Build ordered explicit price-list scopes: [branchId, priceListId] then [null, priceListId].
     *
     * @return array<int, array{0: int|null, 1: int}>
     */
    private function buildPriceListScopes(?int $branchId, ?int $priceListId): array
    {
        if ($priceListId === null) {
            return [];
        }

        $scopes = [];
        if ($branchId !== null) {
            $scopes[] = [$branchId, $priceListId];
        }
        $scopes[] = [null, $priceListId];

        return $scopes;
    }

    private function queryScopePrice(
        int $merchantId,
        int $unitId,
        float $quantity,
        ?int $scopeBranch,
        ?int $scopeList,
    ): ?object {
        $query = DB::table('product_prices')
            ->where('merchant_id', $merchantId)
            ->where('product_variant_unit_id', $unitId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->where('min_qty', '<=', $quantity);

        $scopeBranch === null ? $query->whereNull('branch_id') : $query->where('branch_id', $scopeBranch);
        $scopeList === null ? $query->whereNull('price_list_id') : $query->where('price_list_id', $scopeList);

        return $query->orderByDesc('min_qty')->orderByDesc('id')->first();
    }

    /**
     * @param  \Illuminate\Support\Collection<int, object>  $candidates
     * @param  array<int, array{0: int|null, 1: int|null}>  $scopes
     */
    private function findFirstScopeMatch($candidates, array $scopes, ?float $maxMinQty = null): ?object
    {
        foreach ($scopes as [$scopeBranch, $scopeList]) {
            $matched = $candidates->first(function ($row) use ($scopeBranch, $scopeList, $maxMinQty) {
                $rowBranch = $row->branch_id !== null ? (int) $row->branch_id : null;
                $rowList = $row->price_list_id !== null ? (int) $row->price_list_id : null;

                if ($rowBranch !== $scopeBranch || $rowList !== $scopeList) {
                    return false;
                }

                if ($maxMinQty !== null && (float) $row->min_qty > $maxMinQty) {
                    return false;
                }

                return true;
            });

            if ($matched) {
                return $matched;
            }
        }

        return null;
    }

    /**
     * @return array{markup_percent: float, rounding_unit: int, rounding_mode: string}|null
     */
    private function resolvePriceListMarkupRule(int $merchantId, ?int $priceListId): ?array
    {
        if ($priceListId === null || ! Schema::hasColumn('price_lists', 'default_markup_percent')) {
            return null;
        }

        $hasRounding = Schema::hasColumn('price_lists', 'rounding_unit');
        $columns = $hasRounding
            ? ['default_markup_percent', 'rounding_unit', 'rounding_mode']
            : ['default_markup_percent'];

        $row = DB::table('price_lists')
            ->where('id', $priceListId)
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->first($columns);

        if (! $row || $row->default_markup_percent === null) {
            return null;
        }

        $markup = (float) $row->default_markup_percent;
        if (abs($markup) <= 0.00001) {
            return null;
        }

        return [
            'markup_percent' => $markup,
            'rounding_unit' => $hasRounding && $row->rounding_unit !== null ? max(0, (int) $row->rounding_unit) : 0,
            'rounding_mode' => $hasRounding && is_string($row->rounding_mode) && in_array($row->rounding_mode, ['nearest', 'up', 'down'], true)
                ? $row->rounding_mode
                : 'nearest',
        ];
    }

    private function validateScopes(int $merchantId, ?int $priceListId, ?int $branchId): void
    {
        if ($priceListId !== null && ! DB::table('price_lists')->where('id', $priceListId)->where('merchant_id', $merchantId)->where('is_active', true)->exists()) {
            abort(404, 'Daftar harga tidak ditemukan.');
        }
        if ($branchId !== null && ! DB::table('branches')->where('id', $branchId)->where('merchant_id', $merchantId)->where('is_active', true)->whereNull('deleted_at')->exists()) {
            abort(404, 'Cabang tidak ditemukan.');
        }
    }

    /**
     * @param  array{markup_percent: float, rounding_unit: int, rounding_mode: string}  $rule
     */
    private function formatMarkupPrice(
        ProductVariantUnit $unit,
        ?object $branchBaseMatch,
        int $priceListId,
        array $rule,
    ): array {
        $base = $this->formatResolvedPrice($unit, $branchBaseMatch);
        $baseAmount = (float) $base['price'];
        $raw = max(0.0, $baseAmount * (1 + ($rule['markup_percent'] / 100)));

        $unitStep = $rule['rounding_unit'];
        if ($unitStep > 0) {
            $ratio = $raw / $unitStep;
            $computed = match ($rule['rounding_mode']) {
                'up' => ceil($ratio - 1e-9) * $unitStep,
                'down' => floor($ratio + 1e-9) * $unitStep,
                default => round($ratio, 0, PHP_ROUND_HALF_UP) * $unitStep,
            };
            $computed = round(max(0.0, $computed), 2);
        } else {
            $computed = round($raw, 2);
        }

        return [
            'price' => $computed,
            'pricing_mode' => 'price_list_markup',
            'source' => 'price_list_markup',
            'price_list_id' => $priceListId,
            'branch_id' => $branchBaseMatch?->branch_id,
            'min_qty' => $branchBaseMatch ? (float) $branchBaseMatch->min_qty : 1,
        ];
    }

    private function formatResolvedPrice(ProductVariantUnit $unit, ?object $price): array
    {
        if (! $price) {
            return ['price' => (float) $unit->default_price, 'pricing_mode' => 'default', 'source' => 'default_price', 'min_qty' => 1];
        }

        $amount = match ($price->pricing_mode) {
            'margin' => (float) $unit->cost * (1 + ((float) $price->price / 100)),
            'follow_center' => (float) $unit->default_price,
            default => (float) $price->price,
        };

        return [
            'price' => round($amount, 2),
            'pricing_mode' => $price->pricing_mode,
            'source' => 'product_price',
            'product_price_id' => $price->id,
            'price_list_id' => $price->price_list_id,
            'branch_id' => $price->branch_id,
            'min_qty' => (float) $price->min_qty,
        ];
    }
}
