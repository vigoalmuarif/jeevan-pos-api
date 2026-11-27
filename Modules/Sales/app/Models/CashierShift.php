<?php

declare(strict_types=1);

namespace Modules\Sales\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Operational\Models\Branch;
use Modules\Sales\Enums\CashierShiftStatus;
use Modules\User\Models\User;

class CashierShift extends BaseModel
{
    use BelongsToMerchant;

    protected $table = 'cashier_shifts';

    protected function casts(): array
    {
        return [
            'status' => CashierShiftStatus::class,
            'opening_cash' => 'decimal:2',
            'expected_cash' => 'decimal:2',
            'actual_cash' => 'decimal:2',
            'variance' => 'decimal:2',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function closedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashierShiftMovement::class)->orderByDesc('created_at')->orderByDesc('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(SalesTransaction::class);
    }
}
