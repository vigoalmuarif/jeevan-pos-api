<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Operational\Models\Branch;
use Modules\Operational\Models\Warehouse;
use Modules\Partner\Models\Customer;
use Modules\Sales\Enums\ReturnFeeBearer;
use Modules\Sales\Enums\SalesPaymentMethod;
use Modules\Sales\Enums\SalesReturnStatus;
use Modules\Sales\Enums\SalesReturnType;
use Modules\User\Models\User;

class SalesReturn extends BaseModel
{
    use BelongsToMerchant, SoftDeletes;

    protected $table = 'sales_returns';

    protected function casts(): array
    {
        return [
            'status' => SalesReturnStatus::class,
            'return_type' => SalesReturnType::class,
            'refund_method' => SalesPaymentMethod::class,
            'return_fee_bearer' => ReturnFeeBearer::class,
            'subtotal' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'total_refund' => 'decimal:2',
            'exchange_total' => 'decimal:2',
            'return_fee' => 'decimal:2',
            'settlement_amount' => 'decimal:2',
            'total_cogs_reversed' => 'decimal:2',
            'total_cogs_exchange' => 'decimal:2',
            'returned_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(SalesTransaction::class, 'sales_transaction_id');
    }

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesReturnItem::class)->orderBy('id');
    }

    public function exchangeItems(): HasMany
    {
        return $this->hasMany(SalesReturnExchangeItem::class)->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
