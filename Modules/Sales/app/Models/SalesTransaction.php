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
use Modules\Product\Models\PriceList;
use Modules\Sales\Enums\SalesDiscountType;
use Modules\Sales\Enums\SalesOrderType;
use Modules\Sales\Enums\SalesTransactionStatus;
use Modules\User\Models\User;

class SalesTransaction extends BaseModel
{
    use BelongsToMerchant, SoftDeletes;

    protected $table = 'sales_transactions';

    protected function casts(): array
    {
        return [
            'status' => SalesTransactionStatus::class,
            'order_type' => SalesOrderType::class,
            'discount_type' => SalesDiscountType::class,
            'subtotal' => 'decimal:2',
            'discount_value' => 'decimal:2',
            'discount_amount' => 'decimal:2',
            'service_charge_rate' => 'decimal:2',
            'service_charge_amount' => 'decimal:2',
            'tax_amount' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'change_amount' => 'decimal:2',
            'total_cogs' => 'decimal:2',
            'sold_at' => 'datetime',
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

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function diningTable(): BelongsTo
    {
        return $this->belongsTo(DiningTable::class, 'dining_table_id');
    }

    public function priceList(): BelongsTo
    {
        return $this->belongsTo(PriceList::class, 'price_list_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SalesTransactionItem::class)->orderBy('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalesPayment::class)->orderBy('id');
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
