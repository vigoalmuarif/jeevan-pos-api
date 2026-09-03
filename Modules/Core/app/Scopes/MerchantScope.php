<?php

namespace Modules\Core\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Helpers\MerchantContext;

class MerchantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        if (MerchantContext::check()) {
            $builder->where(
                $model->getTable() . '.merchant_id',
                MerchantContext::id()
            );
        }
    }
}