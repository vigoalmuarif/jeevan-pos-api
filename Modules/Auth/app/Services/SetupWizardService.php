<?php

namespace Modules\Auth\Services;

use Illuminate\Auth\Access\AuthorizationException;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Traits\ApiResponse;
use Modules\Merchant\Models\{Merchant, Plan, IndustryPackage, Subscription, MerchantIndustryPackage};
use Illuminate\Support\Facades\{DB, Hash};
use Modules\Operational\Services\BranchService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Services\MerchantModuleResolver;
use Modules\Merchant\Services\MerchantService;
use Modules\Permission\Services\RoleService;
use Modules\User\Services\UserService;
use Spatie\Permission\PermissionRegistrar;

class SetupWizardService extends BaseService
{
    use ApiResponse;

    private const TRIAL_DAYS = 14;
    private const TRIAL_PLAN_CODE = 'pro';


    public function __construct(
        private readonly MerchantModuleResolver $moduleResolver,
        private readonly MerchantService $merchantService,
        private readonly BranchService $branchService, 
        private readonly RoleService $roleService,
        private readonly UserService $userService,
    ) {}

    public function setup(array $data): Merchant
    {
        return DB::transaction(function () use ($data) {
            $user = request()->user('merchant');
    
            if($user->merchant_id){
                throw new AuthorizationException("Whopps! satu akun gak boleh lebih dari satu merchant yaa, silahkan daftar dengan akun yang berbeda 🙏");
            }


            // 1. Buat Merchant
            $merchant = $this->merchantService->create($data);

            // 2. Set MerchantContext
            MerchantContext::set($merchant);
            app(PermissionRegistrar::class)->setPermissionsTeamId($merchant->id);
            
            // 3. Buat Branch & Warehouse default
            $branch = $this->branchService->create([
                'name' => 'Main Branch',
                'code' => 'MAIN',
                'is_main' => true,
                'is_active' => true,
            ]);

            // 4. Buat Default Roles
            $this->roleService->createDefaultRoles($merchant->id);

            // 5. Assign role
            $this->userService->assignRole($user, 'owner');

            // 6. Assign branch
            // otomatis is_all_branches = true ga perlu di assign

            $this->setupTrialSubscription($merchant, $data['industry_package_code']);

            $user->merchant_id = $merchant->id;
            $user->current_active_branch = $branch->id;
            $user->save();

            return $merchant->fresh();
        });
    }


    private function setupTrialSubscription(Merchant $merchant, string $industry_package_code): Subscription
    {
        $plan = Plan::where('code', self::TRIAL_PLAN_CODE)
            ->where('is_active', true)
            ->firstOrFail();

        $planPrice = $plan->currentPrice('monthly');

        $trialEndsAt = now()->addDays(self::TRIAL_DAYS);

        $subscription = Subscription::create([
            'merchant_id' => $merchant->id,
            'plan_id' => $plan->id,
            'plan_price_id' => $planPrice?->id,
            'base_price_snapshot' => $planPrice?->price ?? 0,
            'currency_snapshot' => $planPrice?->currency ?? 'IDR',
            'billing_cycle' => 'monthly',
            'status' => 'trial',
            'current_period_start' => now(),
            'current_period_end' => $trialEndsAt,
            'trial_ends_at' => $trialEndsAt,
        ]);

        $this->activateIndustryPackage($merchant, $industry_package_code);

        $this->moduleResolver->syncModules($merchant);

        return $subscription;
    }

    private function activateIndustryPackage(Merchant $merchant, string $packageCode): void
    {
        $package = IndustryPackage::where('code', $packageCode)
            ->where('is_active', true)
            ->first();

        if (!$package) {
            return; // package tidak ditemukan — merchant hanya dapat module dari plan
        }

        $packagePrice = $package->currentPrice('monthly');

        MerchantIndustryPackage::create([
            'merchant_id' => $merchant->id,
            'industry_package_id' => $package->id,
            'is_active' => true,
            'price_snapshot' => $packagePrice?->price ?? 0,
            'currency_snapshot' => $packagePrice?->currency ?? 'IDR',
            'billing_cycle_snapshot' => 'monthly',
            'industry_package_price_id' => $packagePrice?->id,
            'activated_at' => now(),
        ]);
    }
}