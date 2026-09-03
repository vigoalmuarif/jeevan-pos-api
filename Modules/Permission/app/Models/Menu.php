<?php

namespace Modules\Permission\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Merchant\Models\Module;

// use Modules\Permission\Database\Factories\MenuFactory;

class Menu extends Model
{
    use HasFactory;

   public function childrens(): HasMany
   {
        return $this->hasMany(Menu::class, 'parent_id');
   }

   public function permission(): BelongsTo
   {
        return $this->belongsTo(Permission::class);
   }

   public function module(): BelongsTo
   {
        return $this->belongsTo(Module::class);
   }
}
