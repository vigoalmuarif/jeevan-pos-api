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
        $branch->loadMissing(['region', 'mainWarehouse', 'province', 'regency', 'district', 'village']);

        return [
            'id'             => $branch->id,
            'name'           => $branch->name,
            'slug'           => $branch->slug,
            'code'           => $branch->code,
            'is_main'        => (bool) $branch->is_main,
            'is_active'      => (bool) $branch->is_active,
            'email'          => $branch->email,
            'phone'          => $branch->phone,
            'receipt_header' => $branch->receipt_header,
            'receipt_footer' => $branch->receipt_footer,
            'address'        => [
                'street'      => $branch->address,
                'postal_code' => $branch->postal_code,
                'village'     => $branch->village?->nama,
                'district'    => $branch->district?->nama,
                'regency'     => $branch->regency?->nama,
                'province'    => $branch->province?->nama,
            ],
            'region'         => $branch->region ? [
                'id'   => $branch->region->id,
                'name' => $branch->region->name,
                'code' => $branch->region->code,
                'type' => $branch->region->type,
            ] : null,
            'main_warehouse' => $branch->mainWarehouse ? [
                'id'   => $branch->mainWarehouse->id,
                'name' => $branch->mainWarehouse->name,
                'code' => $branch->mainWarehouse->code,
            ] : null,
            'total_warehouses' => $branch->warehouses()->count(),
            'total_users'      => $branch->users()->count(),
            'created_at'       => $branch->created_at?->toISOString(),
            'updated_at'       => $branch->updated_at?->toISOString(),
        ];
    }

    public function getResources(Branch $branch): array
    {
        return [
            'users' => $branch->users()
                ->select('users.id', 'users.name', 'users.username', 'users.phone', 'users.email', 'users.is_active')
                ->with('roles:id,name')
                ->get()
                ->map(fn($user) => [
                    'id'        => $user->id,
                    'name'      => $user->name,
                    'username'  => $user->username,
                    'phone'     => $user->phone,
                    'email'     => $user->email,
                    'is_active' => (bool) $user->is_active,
                    'roles'     => $user->roles->pluck('name'),
                ]),

            'warehouses' => $branch->warehouses()
                ->with('regions:id,name,code')
                ->get()
                ->map(fn($wh) => [
                    'id'         => $wh->id,
                    'code'       => $wh->code,
                    'name'       => $wh->name,
                    'type'       => $wh->type,
                    'is_primary' => (bool) ($wh->pivot->is_primary ?? false),
                    'is_active'  => (bool) $wh->is_active,
                    'regions'    => $wh->regions->map(fn($r) => [
                        'id'   => $r->id,
                        'name' => $r->name,
                        'code' => $r->code,
                    ]),
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
