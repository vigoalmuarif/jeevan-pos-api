<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Core\Abstracts\BaseModel;

class Subscription extends BaseModel
{
    protected $casts = [
        'metadata'      => 'array',
        'trial_ends_at' => 'datetime',
        'starts_at'     => 'datetime',
        'ends_at'       => 'datetime',
        'cancelled_at'  => 'datetime',
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }

    public function isTrial(): bool
    {
        return $this->status === 'trial' &&
               $this->trial_ends_at?->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->ends_at?->isPast() ?? false;
    }
}