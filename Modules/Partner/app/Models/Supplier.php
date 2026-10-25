<?php

namespace Modules\Partner\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Models\Wilayah;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Product\Models\Product;

// use Modules\Partner\Database\Factories\SupplierFactory;

class Supplier extends BaseModel
{
    use HasFactory,  BelongsToMerchant;

       public function region(): BelongsTo
    {
        return $this->belongsTo(Wilayah::class, 'region_code', 'kode');
    }

       public function products(): BelongsToMany
       {
           return $this->belongsToMany(Product::class, 'product_supplier')
               ->withPivot('merchant_id')
               ->withTimestamps();
       }

    /**
     * The attributes that are mass assignable.
     */
    // protected $fillable = [];

    // protected static function newFactory(): SupplierFactory
    // {
    //     // return SupplierFactory::new();
    // }
}
