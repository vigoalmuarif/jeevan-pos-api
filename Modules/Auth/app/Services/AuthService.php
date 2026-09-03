<?php

namespace Modules\Auth\Services;

use Exception;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\Hash;
use Modules\Auth\Transformers\AuthBranchResource;
use Modules\Auth\Transformers\AuthMerchantResource;
use Modules\Auth\Transformers\AuthModuleResource;
use Modules\Auth\Transformers\AuthSubscriptionResource;
use Modules\Auth\Transformers\AuthUserResource;
use Modules\Operational\Models\Branch;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Traits\ApiResponse;
use Modules\Merchant\Models\Merchant;
use Modules\Merchant\Services\MerchantService;
use Modules\Permission\Models\Menu;
use Modules\Permission\Services\PermissionCatalogService;
use Modules\User\Models\User;

class AuthService extends BaseService
{
    use ApiResponse;

    public function __construct(
        private readonly MerchantService $merchantService,
        private readonly PermissionCatalogService $permissionCatalogService,
    ) {}

    public function login(array $request)
    {

        // Cari user
        $user = User::where(function ($query) use ($request) {
            $query->where('username', $request['username']);
        })
            ->first();

        // Validasi kredensial
        if (!$user || !Hash::check($request['password'], $user->password)) {
            throw new AuthenticationException('Email atau password salah');
        }

        $accessible = $this->getAccessible($user);

        auth()->guard('merchant')->login($user);

        request()->session()->regenerate();

        $user->last_login_at = now();
        $user->save();

        return $accessible;
    }


    public function logout(Request $request): void
    {
        session()->forget('branch_id');
        auth()->guard('merchant')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
    }

    // -------------------------------------------------------
    //  Helpers
    // -------------------------------------------------------

    public function getAccessible(User $user)
    {
        $merchant = null;
        $branch = null;
        $roles = null;
        $modules = null;


        // Cek status user
        if (!$user->isActive()) {
            throw new AuthorizationException("Whopps! Akun kamu udah gak aktif 😭");
        }

        // ketika baru daftar gak punya merchant & branch, lewati flow ini
        if ($user->merchant_id) {
            // Ambil merchant yang bisa diakses
            $merchant = $this->getAccessibleMerchant($user);

            // Ambil branch yang bisa diakses
            $branch = $this->getAccessibleBranches($user);

            // Ambil roles yang bisa diakses
            $roles = $this->getAccessibleRoles($user);

            // Ambil roles yang bisa diakses
            $modules = $this->getAccessibleModules($merchant);
        }

        return $this->buildAuthPayload(
            $user,
            $merchant,
            $branch,
            $roles,
            $modules
        );
    }


    public function getAccessibleMerchant(User $user)
    {
        $user = $user->load('merchant');

        // Cek status merchant
        if (in_array($user->merchant->status, ['inactive', 'suspended'])) {
            throw new AuthorizationException("Whopps! Merchant udah gak atif 😭");
        }

        $this->merchantService->resolveMerchantContext($user);

        return $user->merchant;
    }

    private function getAccessibleBranches(User $user)
    {
        $branch = $user->load('currentActiveBranch');

        if ($user->is_all_branches) {

            return Branch::where('is_main', true)
                ->first();
        } elseif ($user->current_active_branch) {

            if (!$branch->current_active_branch->is_active) throw new AuthorizationException("Whopps! Cabang udah gak atif 😭");

            return $branch->current_active_branch;
        } elseif (!$branch->current_active_branch) {

            $branch = $user->branches()
                ->where('is_active', true)
                ->first();

            if (!$branch) throw new AuthorizationException("Whopps! Kamu gak punya akses ke cabang atau cabang udah gak atif 😭");

            $user->current_active_branch = $branch->id;
            $user->save();

            return $branch;
        } else {

            throw new AuthorizationException("Whopps! Kamu gak punya akses ke cabang atau cabang udah gak atif 😭");
        }
    }

    public function getAccessibleRoles(User $user)
    {
        $user = $user->load('roles');

        // Cek status merchant
        if ($user->roles->count() === 0) {
            throw new AuthorizationException("Whopps! Kamu gak punya role akses 😭");
        }

        return $user?->roles;
    }

    public function getAccessibleModules(Merchant $merchant)
    {
        $merchant = $merchant->load('activeModules');

        // Cek status merchant
        if ($merchant->activeModules->count() === 0) {
            throw new AuthorizationException("Whopps! Merchant gak punya modul aktif 😭");
        }

        return $merchant?->activeModules?->pluck('code')->values();
    }

    public function getAccessibleMenus(Merchant $merchant)
    {
        $merchant = $merchant->load('activeModules');



        return $merchant->activeModules;
    }

    public function buildAuthPayload(
        User $user,
        ?Merchant $merchant,
        ?Branch $branch,
        ?SupportCollection $roles,
        ?SupportCollection $modules
    ): array {

        $merchant = $merchant ? $merchant?->load(['primaryIndustryPackage', 'activeSubscription', 'activeSubscription.plan']) : null;

        // $this->permissionCatalogService->forget();
        $permissions = $user->hasRole('owner') || $user->is_owner
            ? $this->permissionCatalogService?->allNames()
            : $user?->getAllPermissions()?->pluck('name')->values();

        return [
            'needs_setup_wizard' => !($merchant && $branch && $modules && $roles),
            'user' => AuthUserResource::make($user),
            'merchant' => $merchant ? AuthMerchantResource::make($merchant) : null,
            'active_branch' => $branch ? AuthBranchResource::make($branch) : null,
            'industry_name' => $merchant ? $merchant?->primaryIndustryPackage?->name : null,
            'subscription' => $merchant ? AuthSubscriptionResource::make($merchant) : null,
            'is_all_branches' => $user->is_all_branches,
            'roles' => $roles ? $roles?->pluck('name')->values() : [],
            'permissions' => $permissions,
            'modules' => $modules ?? [],
        ];
    }
}
