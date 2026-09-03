<?php

namespace Modules\Core\Traits;

use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Scopes\MerchantOrGlobalScope;

trait BelongsToMerchantOrGlobal
{
    public static function bootBelongsToMerchantOrGlobal(): void
    {
        static::addGlobalScope(new MerchantOrGlobalScope());

        static::creating(function ($model) {
            if (is_null($model->merchant_id)) {
                $model->merchant_id = MerchantContext::id();
            }
        });
    }

    public function isGlobal(): bool
    {
        return is_null($this->merchant_id);
    }

    public function isOwnedByCurrentMerchant(): bool
    {
        return $this->merchant_id === MerchantContext::get()?->id;
    }
}