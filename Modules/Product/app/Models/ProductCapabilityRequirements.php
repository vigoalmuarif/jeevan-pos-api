<?php

namespace Modules\Product\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Product\Database\Factories\ProductCapabilityRequirementsFactory;

class ProductCapabilityRequirements extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): ProductCapabilityRequirementsFactory
    // {
    //     // return ProductCapabilityRequirementsFactory::new();
    // }
}
