<?php

namespace Modules\Operational\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Operational\Database\Factories\WarehouseProductFactory;

class WarehouseProduct extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): WarehouseProductFactory
    // {
    //     // return WarehouseProductFactory::new();
    // }
}
