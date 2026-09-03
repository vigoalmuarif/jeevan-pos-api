<?php

namespace Modules\Operational\Services;

use Modules\Operational\Models\Branch;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Merchant\Models\MerchantModule;

class BranchDetailService extends BaseService
{
    public function getOperational(Branch $branch): array
    {
        return [
            // 'timezone'            => $branch->timezone,
            // 'transaction_prefix'  => $branch->transaction_prefix,
            // 'hours'               => $branch->operationalHours,
            // // tambahkan field lain sesuai kebutuhan
        ];
    }

    public function getResources(Branch $branch): array
    {
        return [
            'users' => $branch->users()
                ->select('id', 'name', 'email', 'status')
                ->with('roles:id,name')
                ->get()
                ->map(fn($user) => [
                    'id'     => $user->id,
                    'name'   => $user->name,
                    'username'   => $user->username,
                    'phone'   => $user->phone,
                    'email'  => $user->email,
                    'status' => $user->status,
                    'roles'  => $user->roles->pluck('name'),
                ]),

            'warehouses' => $branch->warehouses()
                ->select('id', 'name', 'type', 'status')
                ->get()
                ->map(fn($wh) => [
                    'id'     => $wh->id,
                    'code'   => $wh->code,
                    'name'   => $wh->name,
                    'is_main'   => $wh->is_main,
                    'is_active'   => $wh->is_active,
                ]),
        ];
    }

    public function getSnapshot(Branch $branch): array
    {
        // Akan diisi saat modul Sales sudah ada
        // Placeholder untuk sekarang
        return [
            'today_transactions' => 0,
            'today_revenue'      => 0,
            'month_transactions' => 0,
            'month_revenue'      => 0,
        ];
    }

    public function hasSalesModule(): bool
    {
        $merchantId = MerchantContext::id();

        return MerchantModule::where('merchant_id', $merchantId)
            ->whereHas('module', fn($q) => $q->where('slug', 'sales'))
            ->exists();
    }
}
