<?php

namespace Modules\Purchase\Calculation;

use Modules\Purchase\Enums\DiscountType;

/**
 * Parameter level dokumen untuk PurchaseCalculator.
 *
 * @param string $discountValue  persen (0-100) atau nominal, sesuai $discountType
 * @param bool   $isTaxInclusive true = harga satuan yang diinput SUDAH termasuk pajak
 * @param bool   $taxInCost      true = pajak ikut menjadi HPP; false = PPN masukan dikreditkan
 *                               (nilai default berasal dari Setting merchant)
 */
final readonly class CalculationHeader
{
    public function __construct(
        public ?DiscountType $discountType = null,
        public string $discountValue = '0',
        public bool $isTaxInclusive = false,
        public bool $taxInCost = false,
    ) {
    }
}