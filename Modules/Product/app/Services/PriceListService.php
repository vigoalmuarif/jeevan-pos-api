<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Product\Models\PriceList;
use Modules\Product\Models\ProductPrice;

class PriceListService
{
    public function all()
    {
        return PriceList::query()
            ->where('merchant_id', $this->merchantId())
            ->withCount(['prices as active_prices_count' => fn ($q) => $q->where('is_active', true)])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();
    }

    public function find(int $id): PriceList
    {
        return PriceList::query()->where('merchant_id', $this->merchantId())->findOrFail($id);
    }

    public function create(array $data): PriceList
    {
        return DB::transaction(function () use ($data) {
            $merchantId = $this->merchantId();
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()->where('merchant_id', $merchantId)->update(['is_default' => false]);
            }
            return PriceList::query()->create([
                ...$data,
                'merchant_id' => $merchantId,
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    public function update(int $id, array $data): PriceList
    {
        return DB::transaction(function () use ($id, $data) {
            $merchantId = $this->merchantId();
            $list = PriceList::query()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($id);
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()->where('merchant_id', $merchantId)->whereKeyNot($id)->update(['is_default' => false]);
            }
            if (($data['is_active'] ?? null) === false && $list->is_default && ! ($data['is_default'] ?? false)) {
                $list->is_default = false;
            }
            $list->fill($data)->save();
            return $list->refresh();
        });
    }

    public function deactivate(int $id): void
    {
        $list = $this->find($id);
        if ($list->prices()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['price_list' => 'Daftar harga masih dipakai oleh harga aktif. Nonaktifkan atau hapus harga terkait terlebih dahulu.']);
        }
        $list->is_active = false;
        $list->is_default = false;
        $list->save();
    }

    /**
     * Paginated product-variant-unit price matrix for a specific PriceList (ZERO N+1).
     *
     * @param  array{search?: ?string, only_overridden?: bool, page?: int, per_page?: int}  $filters
     * @return array<string, mixed>
     */
    public function listItems(int $priceListId, array $filters = []): array
    {
        $merchantId = $this->merchantId();
        $list = PriceList::query()
            ->where('merchant_id', $merchantId)
            ->findOrFail($priceListId);

        $perPage = max(5, min(100, (int) ($filters['per_page'] ?? 25)));
        $search = trim((string) ($filters['search'] ?? ''));
        $onlyOverridden = filter_var($filters['only_overridden'] ?? false, FILTER_VALIDATE_BOOLEAN);

        $query = DB::table('product_variant_units as pvu')
            ->join('product_variants as pv', 'pv.id', '=', 'pvu.product_variant_id')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'pvu.unit_id')
            ->leftJoin('product_prices as pp', function ($join) use ($merchantId, $priceListId) {
                $join->on('pp.product_variant_unit_id', '=', 'pvu.id')
                    ->where('pp.merchant_id', '=', $merchantId)
                    ->where('pp.price_list_id', '=', $priceListId)
                    ->whereNull('pp.branch_id')
                    ->where('pp.min_qty', '=', 1)
                    ->where('pp.is_active', '=', true)
                    ->whereNull('pp.deleted_at');
            })
            ->where('pvu.merchant_id', $merchantId)
            ->where('pv.merchant_id', $merchantId)
            ->where('p.merchant_id', $merchantId)
            ->whereNull('pvu.deleted_at')
            ->whereNull('pv.deleted_at')
            ->whereNull('p.deleted_at')
            ->where('p.is_active', true)
            ->where('pv.is_active', true)
            ->select([
                'pvu.id as product_variant_unit_id',
                'p.id as product_id',
                'p.name as product_name',
                'pv.id as product_variant_id',
                'pv.name as variant_name',
                'pv.sku',
                'u.name as unit_name',
                'u.code as unit_code',
                'pvu.is_base',
                'pvu.cost',
                'pvu.default_price',
                'pp.id as product_price_id',
                'pp.price as override_price',
                'pp.pricing_mode as override_pricing_mode',
            ]);

        if ($search !== '') {
            $like = '%'.strtolower($search).'%';
            $query->where(function ($q) use ($like) {
                $q->whereRaw('LOWER(p.name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(pv.name, \'\')) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(COALESCE(pv.sku, \'\')) LIKE ?', [$like]);
            });
        }

        if ($onlyOverridden) {
            $query->whereNotNull('pp.id');
        }

        $paginator = $query
            ->orderBy('p.name')
            ->orderBy('pv.id')
            ->orderByDesc('pvu.is_base')
            ->orderBy('pvu.id')
            ->paginate($perPage);

        $markupPercent = $list->default_markup_percent !== null ? (float) $list->default_markup_percent : null;
        $roundingUnit = $list->rounding_unit !== null ? max(0, (int) $list->rounding_unit) : 0;
        $roundingMode = is_string($list->rounding_mode) && in_array($list->rounding_mode, ['nearest', 'up', 'down'], true)
            ? $list->rounding_mode
            : 'nearest';

        $rows = collect($paginator->items())->map(function ($row) use ($markupPercent, $roundingUnit, $roundingMode) {
            $defaultPrice = round((float) $row->default_price, 2);
            $autoPrice = $this->computeAutoPrice($defaultPrice, $markupPercent, $roundingUnit, $roundingMode);

            $overridePrice = null;
            if ($row->product_price_id !== null) {
                $overridePrice = match ($row->override_pricing_mode) {
                    'margin' => round((float) $row->cost * (1 + ((float) $row->override_price / 100)), 2),
                    'follow_center' => $defaultPrice,
                    default => round((float) $row->override_price, 2),
                };
            }

            return [
                'product_variant_unit_id' => (int) $row->product_variant_unit_id,
                'product_id' => (int) $row->product_id,
                'product_name' => (string) $row->product_name,
                'product_variant_id' => (int) $row->product_variant_id,
                'variant_name' => $row->variant_name,
                'sku' => $row->sku,
                'unit_name' => (string) ($row->unit_name ?? 'Pcs'),
                'unit_code' => $row->unit_code,
                'is_base' => (bool) $row->is_base,
                'default_price' => $defaultPrice,
                'auto_price' => $autoPrice,
                'override_price' => $overridePrice,
                'effective_price' => $overridePrice ?? $autoPrice,
                'is_overridden' => $row->product_price_id !== null,
            ];
        })->values()->all();

        return [
            'price_list' => [
                'id' => (int) $list->id,
                'name' => (string) $list->name,
                'code' => $list->code,
                'default_markup_percent' => $markupPercent,
                'rounding_unit' => $roundingUnit > 0 ? $roundingUnit : null,
                'rounding_mode' => $roundingUnit > 0 ? $roundingMode : null,
            ],
            'data' => $rows,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Bulk upsert or clear global min_qty=1 override prices for a PriceList (strict tenant isolation).
     *
     * @param  array<int, array{product_variant_unit_id: int, override_price: float|int|null}>  $items
     * @return array{updated: int, cleared: int}
     */
    public function bulkUpsertItems(int $priceListId, array $items): array
    {
        $merchantId = $this->merchantId();
        $list = PriceList::query()
            ->where('merchant_id', $merchantId)
            ->findOrFail($priceListId);

        $unitIds = array_values(array_unique(array_map(
            fn (array $row) => (int) $row['product_variant_unit_id'],
            $items,
        )));

        $validCount = DB::table('product_variant_units')
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->whereIn('id', $unitIds)
            ->count();

        if ($validCount !== count($unitIds)) {
            throw ValidationException::withMessages([
                'items' => 'Salah satu satuan produk tidak valid atau tidak dimiliki merchant ini.',
            ]);
        }

        $userId = auth()->guard('merchant')->id();
        $updated = 0;
        $cleared = 0;

        DB::transaction(function () use ($merchantId, $list, $items, $unitIds, $userId, &$updated, &$cleared) {
            $existingByUnit = ProductPrice::query()
                ->where('merchant_id', $merchantId)
                ->where('price_list_id', $list->id)
                ->whereNull('branch_id')
                ->where('min_qty', 1)
                ->where('is_active', true)
                ->whereIn('product_variant_unit_id', $unitIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('product_variant_unit_id');

            foreach ($items as $entry) {
                $unitId = (int) $entry['product_variant_unit_id'];
                $rawPrice = $entry['override_price'] ?? null;
                /** @var ProductPrice|null $existing */
                $existing = $existingByUnit->get($unitId);

                if ($rawPrice === null || $rawPrice === '') {
                    if ($existing) {
                        $existing->delete();
                        $cleared++;
                    }
                    continue;
                }

                $priceValue = round(max(0.0, (float) $rawPrice), 2);
                if ($existing) {
                    $existing->fill([
                        'price' => $priceValue,
                        'pricing_mode' => 'fixed',
                        'is_active' => true,
                    ])->save();
                } else {
                    ProductPrice::query()->create([
                        'merchant_id' => $merchantId,
                        'product_variant_unit_id' => $unitId,
                        'price_list_id' => $list->id,
                        'branch_id' => null,
                        'min_qty' => 1,
                        'price' => $priceValue,
                        'pricing_mode' => 'fixed',
                        'is_active' => true,
                        'created_by' => $userId,
                    ]);
                }
                $updated++;
            }
        });

        return [
            'updated' => $updated,
            'cleared' => $cleared,
        ];
    }

    private function computeAutoPrice(
        float $defaultPrice,
        ?float $markupPercent,
        int $roundingUnit,
        string $roundingMode,
    ): float {
        if ($markupPercent === null || abs($markupPercent) <= 0.00001) {
            return $defaultPrice;
        }

        $raw = max(0.0, $defaultPrice * (1 + ($markupPercent / 100)));
        if ($roundingUnit > 0) {
            $ratio = $raw / $roundingUnit;
            $computed = match ($roundingMode) {
                'up' => ceil($ratio - 1e-9) * $roundingUnit,
                'down' => floor($ratio + 1e-9) * $roundingUnit,
                default => round($ratio, 0, PHP_ROUND_HALF_UP) * $roundingUnit,
            };

            return round(max(0.0, $computed), 2);
        }

        return round($raw, 2);
    }

    private function merchantId(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');
        return (int) MerchantContext::id();
    }
}
