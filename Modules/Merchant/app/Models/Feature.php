<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Abstracts\BaseModel;

// use Modules\Merchant\Database\Factories\FetaureFactory;

class Feature extends BaseModel
{
    use HasFactory;

    public function modules(): BelongsToMany
    {
        return $this->belongsToMany(FeatureModule::class, 'feature_modules');
    }
}
