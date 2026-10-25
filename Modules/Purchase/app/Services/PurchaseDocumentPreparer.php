<?php

namespace Modules\Purchase\Services;

use Modules\Purchase\Calculation\CalculationHeader;
use Modules\Purchase\Calculation\CalculationLine;
use Modules\Purchase\Calculation\PurchaseCalculator;
use Modules\Purchase\Enums\DiscountType;
use Modules\Purchase\Support\Numeric;
use Modules\Core\Helpers\MerchantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Menyiapkan dokumen pembelian (PO atau Goods Receipt) dari input tervalidasi:
 *   input -> lengkapi dari master (satuan, pajak) -> PurchaseCalculator -> atribut siap simpan.
 *
 * Dipakai PurchaseOrderService dan GoodsReceiptService agar alur persiapan yang sama
 * tidak ditulis dua kali (DRY). Service pemanggil hanya menyimpan hasilnya.
 */
class PurchaseDocumentPreparer
{
    public function __construct(
        private readonly PurchaseCalculator $calculator,
        private readonly PurchaseLineResolver $lineResolver,
        private readonly PurchaseSettingsResolver $settingsResolver,
    ) {
    }

    /**
     * @param array<string,mixed>       $header     discount_type, discount_value, is_tax_inclusive (opsional)
     * @param list<array<string,mixed>> $lines      baris input (lihat docblock service pemanggil)
     * @param bool                      $forReceipt true = Goods Receipt (menyertakan kolom HPP,
     *                                              tax_in_cost, dan referensi item PO); false = PO
     */
    public function prepare(array $header, array $lines, bool $forReceipt): PreparedPurchaseDocument
    {
        $this->assertSupplierProducts($header, $lines);
        $settings = $this->settingsResolver->get();

        // Harga inklusif/eksklusif: pilihan dokumen bila diisi, jika tidak ikut default merchant.
        $isInclusive = isset($header['is_tax_inclusive'])
            ? (bool) $header['is_tax_inclusive']
            : $settings->pricesIncludeTax;

        $headerType = DiscountType::tryFrom((string) ($header['discount_type'] ?? ''));
        $headerValue = $headerType === null ? '0' : Numeric::string($header['discount_value'] ?? null);

        $resolved = $this->lineResolver->resolve($lines, $settings->defaultTaxRate);

        $normalized = [];
        $calcLines = [];
        foreach ($resolved as $line) {
            $type = DiscountType::tryFrom((string) ($line['discount_type'] ?? ''));

            $row = [
                'product_variant_id' => (int) $line['product_variant_id'],
                'unit_id' => (int) $line['unit_id'],
                'unit_quantity' => Numeric::string($line['unit_quantity'] ?? null),
                'conversion_rate' => Numeric::string($line['conversion_rate']),
                'unit_price' => Numeric::string($line['unit_price'] ?? null),
                'discount_type' => $type,
                'discount_value' => $type === null ? '0' : Numeric::string($line['discount_value'] ?? null),
                'tax_rate' => Numeric::string($line['tax_rate']),
                'notes' => $line['notes'] ?? null,
            ];

            if ($forReceipt) {
                // Penghubung ke item PO (NULL = pembelian langsung tanpa PO).
                $row['purchase_order_item_id'] = isset($line['purchase_order_item_id'])
                    ? (int) $line['purchase_order_item_id']
                    : null;
            }

            $normalized[] = $row;
            $calcLines[] = new CalculationLine(
                $row['unit_quantity'],
                $row['conversion_rate'],
                $row['unit_price'],
                $type,
                $row['discount_value'],
                $row['tax_rate'],
            );
        }

        // Seluruh perhitungan uang terjadi di SINI dan hanya di sini.
        $result = $this->calculator->calculate(
            new CalculationHeader($headerType, $headerValue, $isInclusive, $settings->taxInCost),
            $calcLines
        );

        $items = [];
        foreach ($normalized as $i => $row) {
            $calc = $result->lines[$i];
            $items[] = array_merge(
                $row,
                $calc->toItemAmounts(),
                // Kolom HPP hanya ada pada goods_receipt_items.
                $forReceipt ? $calc->toCostAmounts() : []
            );
        }

        $headerAttributes = [
            'is_tax_inclusive' => $isInclusive,
            'discount_type' => $headerType,
            'discount_value' => $headerValue,
            ...$result->toHeaderAmounts(),
        ];

        if ($forReceipt) {
            // Snapshot kebijakan saat dokumen dibuat: mengubah setting nanti tidak
            // mengubah HPP dokumen yang sudah ada.
            $headerAttributes['tax_in_cost'] = $settings->taxInCost;
        }

        return new PreparedPurchaseDocument($headerAttributes, $items);
    }

    /** A supplier may receive its assigned products and products not assigned to any supplier. */
    private function assertSupplierProducts(array $header, array $lines): void
    {
        $supplierId = (int) ($header['supplier_id'] ?? 0);
        $merchantId = (int) MerchantContext::id();
        $supplierIsValid = DB::table('suppliers')
            ->where('id', $supplierId)
            ->where('merchant_id', $merchantId)
            ->where('is_active', true)
            ->whereNull('deleted_at')
            ->exists();

        if (! $supplierIsValid) {
            throw ValidationException::withMessages([
                'supplier_id' => 'Supplier tidak aktif atau bukan milik merchant ini.',
            ]);
        }

        $productIdsByVariant = DB::table('product_variants as pv')
            ->join('products as p', 'p.id', '=', 'pv.product_id')
            ->whereIn('pv.id', array_values(array_unique(array_map(
                static fn (array $line): int => (int) $line['product_variant_id'],
                $lines,
            ))))
            ->where('pv.merchant_id', $merchantId)
            ->where('p.merchant_id', $merchantId)
            ->pluck('p.id', 'pv.id');

        $productIds = $productIdsByVariant->unique()->values();
        $assignedProductIds = DB::table('product_supplier')
            ->where('merchant_id', $merchantId)
            ->whereIn('product_id', $productIds)
            ->distinct()
            ->pluck('product_id')
            ->all();
        $supplierProductIds = DB::table('product_supplier')
            ->where('merchant_id', $merchantId)
            ->where('supplier_id', $supplierId)
            ->whereIn('product_id', $productIds)
            ->distinct()
            ->pluck('product_id')
            ->all();

        $assignedProductIds = array_fill_keys($assignedProductIds, true);
        $supplierProductIds = array_fill_keys($supplierProductIds, true);
        $errors = [];
        foreach ($lines as $index => $line) {
            $productId = $productIdsByVariant->get((int) $line['product_variant_id']);
            if (
                $productId !== null
                && isset($assignedProductIds[$productId])
                && ! isset($supplierProductIds[$productId])
            ) {
                $errors["items.{$index}.product_variant_id"] =
                    'Produk ini terdaftar pada supplier lain. Pilih produk milik supplier ini atau produk tanpa supplier.';
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }
}