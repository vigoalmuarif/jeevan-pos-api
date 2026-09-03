<?php

namespace Modules\Core\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Modules\Core\Exceptions\MerchantContextNotSetException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Traits\ApiResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class MerchantOrGlobalScope implements Scope
{

    public function apply(Builder $builder, Model $model): void
    {
        $merchantId = MerchantContext::id();

        if (! $merchantId) {
            throw new NotFoundHttpException('Oopss...Merchant gak ketemu 😥');
        }

        $table = $model->getTable();

        $builder->where(function (Builder $query) use ($table, $merchantId) {
            $query->whereNull("{$table}.merchant_id")
                ->orWhere("{$table}.merchant_id", $merchantId);
        });
    }
}