<?php

declare(strict_types=1);

namespace Modules\Sales\Support;

/**
 * Default POS profile presets per industry package code.
 *
 * Industry presets only supply baseline defaults. Actual runtime capabilities
 * are strictly gated by ModuleEntitlementService and optionally overridden by
 * per-branch overrides in `branch_pos_settings`.
 */
final class PosIndustryPresets
{
    public const UI_MODE_GRID = 'grid';
    public const UI_MODE_LIST = 'list';

    public const PRINT_THERMAL_80MM = 'thermal_80mm';
    public const PRINT_THERMAL_58MM = 'thermal_58mm';
    public const PRINT_INVOICE_A5 = 'invoice_a5';

    public const PAYMENT_FLOW_PAY_FIRST = 'pay_first';
    public const PAYMENT_FLOW_PAY_LATER = 'pay_later';
    public const PAYMENT_FLOW_FLEXIBLE = 'flexible';

    /**
     * @return array<int, string>
     */
    public static function allowedUiModes(): array
    {
        return [
            self::UI_MODE_GRID,
            self::UI_MODE_LIST,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedPrintTemplates(): array
    {
        return [
            self::PRINT_THERMAL_80MM,
            self::PRINT_THERMAL_58MM,
            self::PRINT_INVOICE_A5,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedPaymentFlows(): array
    {
        return [
            self::PAYMENT_FLOW_PAY_FIRST,
            self::PAYMENT_FLOW_PAY_LATER,
            self::PAYMENT_FLOW_FLEXIBLE,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function allowedFnbOrderTypes(): array
    {
        return ['dine_in', 'takeaway', 'delivery'];
    }

    /**
     * Module code required for each module-gated capability.
     *
     * @return array<string, string>
     */
    public static function moduleGates(): array
    {
        return [
            'table_management' => 'table_management',
            'kitchen_display' => 'kitchen_display',
            'material_calculator' => 'material_calculation',
            'delivery_order' => 'delivery_order',
            'salesman' => 'salesman',
        ];
    }

    /**
     * Resolve default preset configuration for an industry package code.
     *
     * @return array{
     *     industry_preset: string,
     *     default_ui_mode: string,
     *     print_template: string,
     *     service_charge_rate: float,
     *     capabilities: array<string, bool>
     * }
     */
    public static function for(?string $industryCode): array
    {
        $code = strtolower(trim((string) $industryCode));

        return match ($code) {
            'cafe', 'fnb', 'restaurant', 'resto', 'coffee_shop', 'bakery' => [
                'industry_preset' => 'cafe',
                'default_ui_mode' => self::UI_MODE_GRID,
                'print_template' => self::PRINT_THERMAL_80MM,
                'service_charge_rate' => 0.0,
                'enabled_order_types' => ['dine_in', 'takeaway', 'delivery'],
                'payment_flow' => self::PAYMENT_FLOW_FLEXIBLE,
                'auto_cleaning_after_payment' => false,
                'capabilities' => [
                    'barcode_fast_scan' => false,
                    'multi_unit' => false,
                    'decimal_qty' => false,
                    'item_notes' => true,
                    'order_type' => true,
                    'modifiers' => true,
                    'service_charge' => true,
                    'table_management' => true,
                    'kitchen_display' => true,
                    'material_calculator' => false,
                    'delivery_order' => false,
                    'salesman' => false,
                    'credit_sale' => false,
                    'require_customer' => false,
                ],
            ],
            'wholesale' => [
                'industry_preset' => 'wholesale',
                'default_ui_mode' => self::UI_MODE_LIST,
                'print_template' => self::PRINT_INVOICE_A5,
                'service_charge_rate' => 0.0,
                'capabilities' => [
                    'barcode_fast_scan' => true,
                    'multi_unit' => true,
                    'decimal_qty' => false,
                    'item_notes' => true,
                    'order_type' => false,
                    'modifiers' => false,
                    'service_charge' => false,
                    'table_management' => false,
                    'kitchen_display' => false,
                    'material_calculator' => false,
                    'delivery_order' => false,
                    'salesman' => false,
                    'credit_sale' => true,
                    'require_customer' => false,
                ],
            ],
            'building_material' => [
                'industry_preset' => 'building_material',
                'default_ui_mode' => self::UI_MODE_LIST,
                'print_template' => self::PRINT_INVOICE_A5,
                'service_charge_rate' => 0.0,
                'capabilities' => [
                    'barcode_fast_scan' => true,
                    'multi_unit' => true,
                    'decimal_qty' => true,
                    'item_notes' => true,
                    'order_type' => false,
                    'modifiers' => false,
                    'service_charge' => false,
                    'table_management' => false,
                    'kitchen_display' => false,
                    'material_calculator' => true,
                    'delivery_order' => true,
                    'salesman' => false,
                    'credit_sale' => true,
                    'require_customer' => false,
                ],
            ],
            'distributor' => [
                'industry_preset' => 'distributor',
                'default_ui_mode' => self::UI_MODE_LIST,
                'print_template' => self::PRINT_INVOICE_A5,
                'service_charge_rate' => 0.0,
                'capabilities' => [
                    'barcode_fast_scan' => false,
                    'multi_unit' => true,
                    'decimal_qty' => true,
                    'item_notes' => true,
                    'order_type' => false,
                    'modifiers' => false,
                    'service_charge' => false,
                    'table_management' => false,
                    'kitchen_display' => false,
                    'material_calculator' => false,
                    'delivery_order' => true,
                    'salesman' => true,
                    'credit_sale' => true,
                    'require_customer' => true,
                ],
            ],
            'laundry' => [
                'industry_preset' => 'laundry',
                'default_ui_mode' => self::UI_MODE_GRID,
                'print_template' => self::PRINT_THERMAL_80MM,
                'service_charge_rate' => 0.0,
                'capabilities' => [
                    'barcode_fast_scan' => false,
                    'multi_unit' => false,
                    'decimal_qty' => true,
                    'item_notes' => true,
                    'order_type' => false,
                    'modifiers' => true,
                    'service_charge' => false,
                    'table_management' => false,
                    'kitchen_display' => false,
                    'material_calculator' => false,
                    'delivery_order' => false,
                    'salesman' => false,
                    'credit_sale' => true,
                    'require_customer' => true,
                ],
            ],
            default => [
                'industry_preset' => $code !== '' ? $code : 'retail',
                'default_ui_mode' => self::UI_MODE_GRID,
                'print_template' => self::PRINT_THERMAL_80MM,
                'service_charge_rate' => 0.0,
                'capabilities' => [
                    'barcode_fast_scan' => true,
                    'multi_unit' => true,
                    'decimal_qty' => false,
                    'item_notes' => false,
                    'order_type' => false,
                    'modifiers' => false,
                    'service_charge' => false,
                    'table_management' => false,
                    'kitchen_display' => false,
                    'material_calculator' => false,
                    'delivery_order' => false,
                    'salesman' => false,
                    'credit_sale' => false,
                    'require_customer' => false,
                ],
            ],
        };
    }
}
