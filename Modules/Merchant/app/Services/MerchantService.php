<?php

namespace Modules\Merchant\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\Merchant;
use Modules\User\Models\User;
use Spatie\Permission\PermissionRegistrar;

class MerchantService extends BaseService
{

    // -------------------------------------------------------
    // List & Show
    // -------------------------------------------------------

    public function list(array $filters = []): LengthAwarePaginator
    {
        return Merchant::withCount(['users', 'branches'])
            ->when(
                isset($filters['search']),
                fn($q) => $q->where(function ($q) use ($filters) {
                    $q->where('name', 'like', "%{$filters['search']}%")
                        ->orWhere('email', 'like', "%{$filters['search']}%")
                        ->orWhere('slug', 'like', "%{$filters['search']}%");
                })
            )
            ->when(
                isset($filters['status']),
                fn($q) => $q->where('status', $filters['status'])
            )
            ->latest()
            ->paginate($filters['per_page'] ?? 15);
    }

    // -------------------------------------------------------
    // Core Create — hanya buat merchant saja
    // -------------------------------------------------------

    public function create(array $data): Merchant
    {
        return Merchant::create([
            'ulid' => Str::ulid(),
            'name' => $data['name'],
            'slug' => isset($data['slug']) ? Str::slug($data['slug']) : null,
            'industry_package_code' => $data['industry_package_code'],
            'email' => $data['email'] ?? request()->user()->email,
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? null,
            'country' => $data['country'] ?? 'ID',
            'timezone' => $data['timezone'] ?? 'Asia/Jakarta',
            'currency' => $data['currency'] ?? 'IDR',
            'locale' => $data['locale'] ?? 'id',
            'status' => $data['status'] ?? 'trial',
        ]);
    }

   

    // -------------------------------------------------------
    // Update
    // -------------------------------------------------------

    public function update(Merchant $merchant, array $data): Merchant
    {
        $this->clearCache($merchant);

        $merchant->update([
            'industry_package_code' => $data['industry_package_code'],
            'name' => $data['name'],
            'slug' => Str::slug($data['slug']) ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'address' => $data['address'] ?? null,
            'city' => $data['city'] ?? null,
            'province' => $data['province'] ?? null,
            'country' => $data['country'] ?? 'ID',
            'timezone' => $data['timezone'] ?? 'Asia/Jakarta',
            'currency' => $data['currency'] ?? 'IDR',
            'locale' => $data['locale'] ?? 'id',
        ]);

        return $merchant->fresh();
    }

    // -------------------------------------------------------
    // Status Management
    // -------------------------------------------------------

    public function suspend(Merchant $merchant): Merchant
    {
        $this->ensureNotAlreadySuspended($merchant);
        $this->clearCache($merchant);

        $merchant->update(['status' => 'suspended']);

        return $merchant->fresh();
    }

    public function activate(Merchant $merchant): Merchant
    {
        $this->clearCache($merchant);

        $merchant->update(['status' => 'active']);

        return $merchant->fresh();
    }

    public function delete(Merchant $merchant): void
    {
        $this->ensureNoActiveSubscription($merchant);
        $this->clearCache($merchant);

        $merchant->delete();
    }

    // -------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------

    private function clearCache(Merchant $merchant): void
    {
        Cache::forget("merchant:slug:{$merchant->slug}");
    }

    private function ensureNotAlreadySuspended(
        Merchant $merchant
    ): void {
        if ($merchant->status === 'suspended') {
            throw ValidationException::withMessages([
                'merchant' => 'Merchant is already suspended.',
            ]);
        }
    }

    private function ensureNoActiveSubscription(
        Merchant $merchant
    ): void {
        $hasActive = $merchant->subscriptions()
            ->whereIn('status', ['active', 'trial'])
            ->exists();

        if ($hasActive) {
            throw ValidationException::withMessages([
                'merchant' => 'Cannot delete merchant with active subscription.',
            ]);
        }
    }

    
    public function resolveMerchantContext(User $user): void
    {
        MerchantContext::set($user->merchant);
        app(PermissionRegistrar::class)->setPermissionsTeamId($user->merchant_id);
    }

   
}