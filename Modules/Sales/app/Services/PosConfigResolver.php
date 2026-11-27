<?php

declare(strict_types=1);

namespace Modules\Sales\Services;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Services\ModuleEntitlementService;
use Modules\Sales\Models\BranchPosSetting;
use Modules\Sales\Support\PosIndustryPresets;

class PosConfigResolver
{
    public function __construct(
        private readonly ModuleEntitlementService $entitlementService,
    ) {}

    /**
     * Resolve effective POS config for a specific branch (or merchant baseline if branchId is null).
     *
     * Priority order:
     * 1. Industry package preset (merchants.industry_code)
     * 2. Per-branch operational overrides (branch_pos_settings)
     * 3. Hard server-side entitlement gate (ModuleEntitlementService)
     *
     * @return array<string, mixed>
     */
    public function resolveForBranch(?int $branchId = null): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $industryCode = $this->resolveMerchantIndustryCode($merchantId);
        $entitledModules = $this->resolveEntitledModules($merchantId);

        $setting = null;
        if ($branchId !== null && Schema::hasTable('branch_pos_settings')) {
            $setting = BranchPosSetting::query()
                ->where('merchant_id', $merchantId)
                ->where('branch_id', $branchId)
                ->first();
        }

        return $this->buildConfig($branchId, $industryCode, $entitledModules, $setting);
    }

    /**
     * Resolve POS configs for multiple branches of the current merchant in a single batch query.
     *
     * @param  array<int, int>  $branchIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveForBranches(array $branchIds): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $branchIds), fn (int $id) => $id > 0)));
        if (empty($cleanIds)) {
            return [];
        }

        $industryCode = $this->resolveMerchantIndustryCode($merchantId);
        $entitledModules = $this->resolveEntitledModules($merchantId);

        $settingsByBranch = collect();
        if (Schema::hasTable('branch_pos_settings')) {
            $settingsByBranch = BranchPosSetting::query()
                ->where('merchant_id', $merchantId)
                ->whereIn('branch_id', $cleanIds)
                ->get()
                ->keyBy('branch_id');
        }

        $result = [];
        foreach ($cleanIds as $branchId) {
            /** @var BranchPosSetting|null $setting */
            $setting = $settingsByBranch->get($branchId);
            $result[$branchId] = $this->buildConfig($branchId, $industryCode, $entitledModules, $setting);
        }

        return $result;
    }

    /**
     * Create or update branch-level POS settings override for the current merchant.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public function updateBranchSetting(int $branchId, array $data): array
    {
        $merchantId = MerchantContext::id();
        abort_unless($merchantId, 403, 'Merchant context is required.');

        $branchExists = DB::table('branches')
            ->where('id', $branchId)
            ->where('merchant_id', $merchantId)
            ->whereNull('deleted_at')
            ->exists();

        if (! $branchExists) {
            throw ValidationException::withMessages([
                'branch_id' => 'Cabang tidak ditemukan.',
            ]);
        }

        $userId = Auth::guard('merchant')->id() ?? Auth::id();

        $attributes = [
            'updated_by' => $userId,
        ];

        $allowedFields = [
            'default_ui_mode',
            'print_template',
            'service_charge_rate',
            'allow_decimal_qty',
            'enable_item_notes',
            'barcode_fast_scan',
            'require_customer',
            'extra_config',
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $attributes[$field] = $data[$field];
            }
        }

        $existingSetting = BranchPosSetting::query()
            ->where('merchant_id', $merchantId)
            ->where('branch_id', $branchId)
            ->first();

        $extraConfig = is_array($existingSetting?->extra_config) ? $existingSetting->extra_config : [];
        if (array_key_exists('extra_config', $data)) {
            $extraConfig = is_array($data['extra_config']) ? array_merge($extraConfig, $data['extra_config']) : [];
        }

        foreach (['enabled_order_types', 'payment_flow', 'auto_cleaning_after_payment'] as $extraKey) {
            if (array_key_exists($extraKey, $data)) {
                if ($data[$extraKey] === null) {
                    unset($extraConfig[$extraKey]);
                } else {
                    $extraConfig[$extraKey] = $data[$extraKey];
                }
            }
        }

        $priceListIdsToCheck = [];

        if (array_key_exists('channel_price_lists', $data)) {
            if ($data['channel_price_lists'] === null) {
                unset($extraConfig['channel_price_lists']);
            } elseif (is_array($data['channel_price_lists'])) {
                $sanitized = [];
                $hasAny = false;
                foreach (PosIndustryPresets::allowedFnbOrderTypes() as $channel) {
                    $val = $data['channel_price_lists'][$channel] ?? null;
                    $intVal = ($val !== null && $val !== '') ? (int) $val : null;
                    $sanitized[$channel] = $intVal && $intVal > 0 ? $intVal : null;
                    if ($sanitized[$channel] !== null) {
                        $priceListIdsToCheck[] = $sanitized[$channel];
                        $hasAny = true;
                    }
                }

                if (! $hasAny) {
                    unset($extraConfig['channel_price_lists']);
                } else {
                    $extraConfig['channel_price_lists'] = $sanitized;
                }
            }
        }

        if (array_key_exists('delivery_channels', $data)) {
            if ($data['delivery_channels'] === null || $data['delivery_channels'] === []) {
                unset($extraConfig['delivery_channels']);
            } elseif (is_array($data['delivery_channels'])) {
                $sanitizedDelivery = [];
                $seenCodes = [];
                foreach ($data['delivery_channels'] as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $code = strtolower(trim((string) ($entry['code'] ?? '')));
                    $name = trim((string) ($entry['name'] ?? ''));
                    if ($code === '' || $name === '' || isset($seenCodes[$code])) {
                        continue;
                    }
                    $seenCodes[$code] = true;
                    $rawPlId = $entry['price_list_id'] ?? null;
                    $plId = ($rawPlId !== null && $rawPlId !== '' && (int) $rawPlId > 0) ? (int) $rawPlId : null;
                    if ($plId !== null) {
                        $priceListIdsToCheck[] = $plId;
                    }
                    $sanitizedDelivery[] = [
                        'code' => $code,
                        'name' => $name,
                        'price_list_id' => $plId,
                    ];
                }

                if (empty($sanitizedDelivery)) {
                    unset($extraConfig['delivery_channels']);
                } else {
                    $extraConfig['delivery_channels'] = $sanitizedDelivery;
                }
            }
        }

        // Batch-validate merchant ownership of all selected price lists (1 query, strict tenant isolation)
        $uniqueIds = array_values(array_unique($priceListIdsToCheck));
        if (! empty($uniqueIds) && Schema::hasTable('price_lists')) {
            $validCount = DB::table('price_lists')
                ->where('merchant_id', $merchantId)
                ->whereIn('id', $uniqueIds)
                ->count();

            if ($validCount !== count($uniqueIds)) {
                throw ValidationException::withMessages([
                    'channel_price_lists' => 'Salah satu Daftar Harga tidak valid atau tidak dimiliki merchant ini.',
                ]);
            }
        }

        $attributes['extra_config'] = empty($extraConfig) ? null : $extraConfig;

        $setting = BranchPosSetting::query()->updateOrCreate(
            [
                'merchant_id' => $merchantId,
                'branch_id' => $branchId,
            ],
            $attributes,
        );

        $industryCode = $this->resolveMerchantIndustryCode($merchantId);
        $entitledModules = $this->resolveEntitledModules($merchantId);

        return $this->buildConfig($branchId, $industryCode, $entitledModules, $setting);
    }

    /**
     * @param  array<int, string>  $entitledModules
     * @return array<string, mixed>
     */
    private function buildConfig(
        ?int $branchId,
        string $industryCode,
        array $entitledModules,
        ?BranchPosSetting $setting,
    ): array {
        $preset = PosIndustryPresets::for($industryCode);
        $capabilities = $preset['capabilities'];

        $defaultUiMode = $preset['default_ui_mode'];
        $printTemplate = $preset['print_template'];
        $serviceChargeRate = (float) $preset['service_charge_rate'];
        $enabledOrderTypes = $preset['enabled_order_types'] ?? ['retail'];
        $paymentFlow = $preset['payment_flow'] ?? PosIndustryPresets::PAYMENT_FLOW_PAY_FIRST;
        $autoCleaningAfterPayment = (bool) ($preset['auto_cleaning_after_payment'] ?? false);
        $channelPriceLists = [
            'dine_in' => null,
            'takeaway' => null,
            'delivery' => null,
        ];
        $deliveryChannels = [];

        // 1. Apply branch-level operational overrides (non-entitlement-gated preferences)
        if ($setting !== null) {
            if ($setting->default_ui_mode !== null && in_array($setting->default_ui_mode, PosIndustryPresets::allowedUiModes(), true)) {
                $defaultUiMode = $setting->default_ui_mode;
            }
            if ($setting->print_template !== null && in_array($setting->print_template, PosIndustryPresets::allowedPrintTemplates(), true)) {
                $printTemplate = $setting->print_template;
            }
            if ($setting->service_charge_rate !== null) {
                $serviceChargeRate = round(max(0.0, min(100.0, (float) $setting->service_charge_rate)), 2);
            }
            if ($setting->allow_decimal_qty !== null) {
                $capabilities['decimal_qty'] = (bool) $setting->allow_decimal_qty;
            }
            if ($setting->enable_item_notes !== null) {
                $capabilities['item_notes'] = (bool) $setting->enable_item_notes;
            }
            if ($setting->barcode_fast_scan !== null) {
                $capabilities['barcode_fast_scan'] = (bool) $setting->barcode_fast_scan;
            }
            if ($setting->require_customer !== null) {
                $capabilities['require_customer'] = (bool) $setting->require_customer;
            }

            $extra = is_array($setting->extra_config) ? $setting->extra_config : [];
            if (! empty($extra['enabled_order_types']) && is_array($extra['enabled_order_types'])) {
                $filtered = array_values(array_unique(array_intersect(
                    array_map('strval', $extra['enabled_order_types']),
                    PosIndustryPresets::allowedFnbOrderTypes(),
                )));
                if (! empty($filtered)) {
                    $enabledOrderTypes = $filtered;
                }
            }
            if (! empty($extra['payment_flow']) && in_array((string) $extra['payment_flow'], PosIndustryPresets::allowedPaymentFlows(), true)) {
                $paymentFlow = (string) $extra['payment_flow'];
            }
            if (array_key_exists('auto_cleaning_after_payment', $extra) && $extra['auto_cleaning_after_payment'] !== null) {
                $autoCleaningAfterPayment = (bool) $extra['auto_cleaning_after_payment'];
            }
            if (! empty($extra['channel_price_lists']) && is_array($extra['channel_price_lists'])) {
                foreach (PosIndustryPresets::allowedFnbOrderTypes() as $channel) {
                    $val = $extra['channel_price_lists'][$channel] ?? null;
                    $channelPriceLists[$channel] = ($val !== null && (int) $val > 0) ? (int) $val : null;
                }
            }
            if (! empty($extra['delivery_channels']) && is_array($extra['delivery_channels'])) {
                foreach ($extra['delivery_channels'] as $entry) {
                    if (! is_array($entry)) {
                        continue;
                    }
                    $code = strtolower(trim((string) ($entry['code'] ?? '')));
                    $name = trim((string) ($entry['name'] ?? ''));
                    if ($code === '' || $name === '') {
                        continue;
                    }
                    $plId = isset($entry['price_list_id']) && (int) $entry['price_list_id'] > 0
                        ? (int) $entry['price_list_id']
                        : null;
                    $deliveryChannels[] = [
                        'code' => $code,
                        'name' => $name,
                        'price_list_id' => $plId,
                    ];
                }
            }
        }

        // 2. Enforce strict server-side module entitlement gates (cannot be bypassed by preset or branch override)
        foreach (PosIndustryPresets::moduleGates() as $capabilityKey => $requiredModuleCode) {
            $capabilities[$capabilityKey] = in_array($requiredModuleCode, $entitledModules, true);
        }

        // If merchant is entitled to F&B modules (table_management or kitchen_display) even under a non-cafe industry_code,
        // automatically activate F&B order_type, modifiers, service_charge, and F&B defaults unless explicitly overridden.
        if (! empty($capabilities['table_management']) || ! empty($capabilities['kitchen_display'])) {
            $capabilities['order_type'] = true;
            $capabilities['modifiers'] = true;
            $capabilities['service_charge'] = true;
            $extra = is_array($setting?->extra_config) ? $setting->extra_config : [];
            if (empty($extra['enabled_order_types']) && $enabledOrderTypes === ['retail']) {
                $enabledOrderTypes = PosIndustryPresets::allowedFnbOrderTypes();
            }
            if (empty($extra['payment_flow']) && ! empty($capabilities['table_management'])) {
                $paymentFlow = PosIndustryPresets::PAYMENT_FLOW_FLEXIBLE;
            }
        }

        // multi_unit is enabled if merchant has the product_unit module OR preset enables it
        if (in_array('product_unit', $entitledModules, true)) {
            $capabilities['multi_unit'] = true;
        }

        // 3. Enforce F&B branch coherence rules (Model A: Booth/Gerobak Takeaway-Only has no Dine-in/Table/SC)
        $hasDineIn = in_array('dine_in', $enabledOrderTypes, true);
        if (! empty($capabilities['order_type']) && ! $hasDineIn) {
            $capabilities['table_management'] = false;
            $capabilities['service_charge'] = false;
            $paymentFlow = PosIndustryPresets::PAYMENT_FLOW_PAY_FIRST;
        }

        // Without table_management entitlement, Open Bill Pay-Later is not available -> fallback to pay_first
        if (empty($capabilities['table_management'])) {
            $paymentFlow = PosIndustryPresets::PAYMENT_FLOW_PAY_FIRST;
        }

        return [
            'branch_id' => $branchId,
            'industry_preset' => $preset['industry_preset'],
            'default_ui_mode' => $defaultUiMode,
            'print_template' => $printTemplate,
            'service_charge_rate' => $serviceChargeRate,
            'enabled_order_types' => $enabledOrderTypes,
            'payment_flow' => $paymentFlow,
            'auto_cleaning_after_payment' => $autoCleaningAfterPayment,
            'channel_price_lists' => $channelPriceLists,
            'delivery_channels' => $deliveryChannels,
            'has_branch_override' => $setting !== null,
            'capabilities' => $capabilities,
        ];
    }

    private function resolveMerchantIndustryCode(int $merchantId): string
    {
        $merchant = MerchantContext::get();
        if ($merchant && (int) $merchant->id === $merchantId) {
            $ctxCode = $merchant->industry_package_code ?? $merchant->industry_code ?? null;
            if (is_string($ctxCode) && trim($ctxCode) !== '') {
                return trim($ctxCode);
            }
        }

        if (Schema::hasColumn('merchants', 'industry_package_code')) {
            $code = DB::table('merchants')
                ->where('id', $merchantId)
                ->value('industry_package_code');

            if (is_string($code) && trim($code) !== '') {
                return trim($code);
            }
        }

        if (Schema::hasColumn('merchants', 'industry_code')) {
            $code = DB::table('merchants')
                ->where('id', $merchantId)
                ->value('industry_code');

            if (is_string($code) && trim($code) !== '') {
                return trim($code);
            }
        }

        // Fallback to active merchant_industry_packages pivot if column on merchants is empty
        if (Schema::hasTable('merchant_industry_packages') && Schema::hasTable('industry_packages')) {
            $pkgCode = DB::table('merchant_industry_packages')
                ->join('industry_packages', 'industry_packages.id', '=', 'merchant_industry_packages.industry_package_id')
                ->where('merchant_industry_packages.merchant_id', $merchantId)
                ->where('merchant_industry_packages.is_active', true)
                ->orderByDesc('merchant_industry_packages.activated_at')
                ->value('industry_packages.code');

            if (is_string($pkgCode) && trim($pkgCode) !== '') {
                return trim($pkgCode);
            }
        }

        return 'retail';
    }

    /**
     * @return array<int, string>
     */
    private function resolveEntitledModules(int $merchantId): array
    {
        if (! Schema::hasTable('modules') || ! Schema::hasTable('merchant_modules')) {
            return [];
        }

        return $this->entitlementService->getEntitledModuleCodes($merchantId);
    }
}
