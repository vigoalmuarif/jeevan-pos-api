<?php

namespace Modules\Purchase\Calculation;

use Modules\Purchase\Enums\DiscountType;

/**
 * Satu baris input untuk PurchaseCalculator.
 *
 * SEMUA angka bertipe string (bukan float) agar presisi uang terjaga. Nilai dari model
 * (cast decimal) sudah berupa string; nilai dari request harus di-cast ke string numerik
 * oleh service sebelum membuat objek ini.
 *
 * @param string $unitQuantity   qty dalam satuan yang diinput (mis. dus)
 * @param string $conversionRate pengali satuan input -> satuan dasar (mis. 12)
 * @param string $unitPrice      harga per satuan input (inklusif pajak bila header->isTaxInclusive)
 * @param string $taxRate        persen pajak 0-100 (sudah termasuk default dari Setting / override user)
 */
final readonly class CalculationLine
{
    public function __construct(
        public string $unitQuantity,
        public string $conversionRate,
        public string $unitPrice,
        public ?DiscountType $discountType = null,
        public string $discountValue = '0',
        public string $taxRate = '0',
    ) {
    }
}