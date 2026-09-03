<?php

namespace Modules\Merchant\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Merchant\Database\Factories\FeatureModuleFactory;

class FeatureModule extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): FeatureModuleFactory
    // {
    //     // return FeatureModuleFactory::new();
    // }
}
