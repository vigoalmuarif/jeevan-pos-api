<?php

namespace Modules\Core\Traits;

trait HasAudit
{
    public static function bootHasAudit(): void
    {
        static::creating(function (self $model) {
            if (auth()->check()) {
                $model->created_by = auth()->id();
                $model->updated_by = auth()->id();
            }
        });

        static::updating(function (self $model) {
            if (auth()->check()) {
                $model->updated_by = auth()->id();
            }
        });
    }
}