<?php

namespace Modules\Operational\Models;

use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Modules\Core\Abstracts\BaseModel;
use Modules\Core\Models\Wilayah;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Merchant\Models\Merchant;
use Modules\User\Models\User;

class Branch extends BaseModel
{
    use SoftDeletes, BelongsToMerchant;

    protected $casts = [
        'metadata'  => 'array',
        'is_main'   => 'boolean',
    ];

    // Relations
    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }


    public function region(): BelongsTo
    {
        return $this->belongsTo(Region::class);
    }

    public function warehouses(): BelongsToMany
    {
        return $this->belongsToMany(Warehouse::class, 'branch_warehouses');
    }

    public function mainWarehouse(): BelongsTo
    {
        return $this->belongsTo(
                Warehouse::class, 
                'primary_warehouse_id', 
                'id'
            );
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(
            User::class,
            'user_branches'
        )->withTimestamps();
    }

    public function village(): BelongsTo
    {
        return $this->belongsTo(
            Wilayah::class,
            'village_code', 'kode'
        );
    }

    public function district(): BelongsTo
    {
        return $this->belongsTo(
            Wilayah::class,
            'district_code', 'kode'
        );
    }

    public function regency(): BelongsTo
    {
        return $this->belongsTo(
            Wilayah::class,
            'regency_code', 'kode'
        );
    }

    public function province(): BelongsTo
    {
        return $this->belongsTo(
            Wilayah::class,
            'province_code', 'kode'
        );
    }

    // Helpers
    public function mainBranch(): bool
    {
        return $this->is_main === true;
    }

    public function isActive(): bool
    {
        return $this->is_active === true;
    }
}