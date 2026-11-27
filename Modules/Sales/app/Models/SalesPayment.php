<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Sales\Enums\SalesPaymentMethod;

class SalesPayment extends BaseModel
{
    use BelongsToMerchant;

    protected $table = 'sales_payments';

    protected function casts(): array
    {
        return [
            'payment_method' => SalesPaymentMethod::class,
            'amount' => 'decimal:2',
            'paid_at' => 'datetime',
        ];
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class, 'sales_transaction_id');
    }
}
