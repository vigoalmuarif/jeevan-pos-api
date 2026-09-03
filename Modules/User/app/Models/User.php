<?php

namespace Modules\User\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Operational\Models\Branch;
use Spatie\Permission\Traits\HasRoles;
use Modules\Core\Traits\BelongsToMerchant;
use Modules\Merchant\Models\Merchant;

class User extends Authenticatable
{
    use HasApiTokens, Notifiable, SoftDeletes, HasRoles, BelongsToMerchant;

    protected $fillable = ['merchant_id', 'name', 'email', 'username', 'phone', 'avatar', 'password', 'is_active', 'is_all_branches'];

       protected string $guard_name = 'merchant';
    protected function getDefaultGuardName(): string { return $this->guard_name; }

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'is_all_branches' => 'boolean'
    ];

    public function merchant(): BelongsTo
    {
        return $this->belongsTo(Merchant::class);
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(
            Branch::class,
            'user_branches'
        )->withTimestamps();
    }

    public function currentActiveBranch(): BelongsTo
    {
        return $this->belongsTo(
            Branch::class,
            'current_active_branch',
            'id'
        );
    }

    public function isActive(): bool
    {
        return $this->is_active === true;
    }

    public function canAccessBranch(int $branchId): bool
    {
        if ($this->is_all_branches) {
            return true;
        }

        return $this->branches()
            ->where('branches.id', $branchId)
            ->exists();
    }
}