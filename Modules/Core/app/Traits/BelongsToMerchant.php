<?php

namespace Modules\Core\Traits;

use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Scopes\MerchantScope;
use Modules\Merchant\Models\Merchant;

trait BelongsToMerchant
{
    public static function bootBelongsToMerchant(): void
    {
        // Auto set merchant_id saat create
        static::creating(function (self $model) {
            if (empty($model->merchant_id) && MerchantContext::check()) {
                $model->merchant_id = MerchantContext::id();
            }
        });

        // Apply global scope
        static::addGlobalScope(new MerchantScope());
    }

    public function Merchant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function scopeForMerchant(
        \Illuminate\Database\Eloquent\Builder $query,
        int $merchantId
    ): \Illuminate\Database\Eloquent\Builder {
        return $query->withoutGlobalScope(MerchantScope::class)
            ->where('merchant_id', $merchantId);
    }
}