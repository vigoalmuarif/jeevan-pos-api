<?php

namespace Modules\Permission\Services;

use Illuminate\Support\Collection;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Permission\Models\Permission;
use Modules\Permission\Models\Role;

class RoleService extends BaseService
{
    // -------------------------------------------------------
    // Role CRUD
    // -------------------------------------------------------

    public function list(): Collection
    {
        return Role::forMerchant(MerchantContext::id())
                   ->with('permissions')
                   ->get();
    }

    public function create(array $data): Role
    {
        $role = Role::create([
            'name'         => $data['name'],
            'display_name' => $data['display_name'],
            'description'  => $data['description'] ?? null,
            'guard_name'   => 'merchant',
            'merchant_id'  => MerchantContext::id(),
            'is_default'   => false,
        ]);

        if (!empty($data['permissions'])) {
            $this->syncPermissions($role, $data['permissions']);
        }

        return $role->load('permissions');
    }

    public function update(Role $role, array $data): Role
    {
        $this->ensureRoleOwnedByMerchant($role);

        $role->update([
            'name'         => $data['name'],
            'display_name' => $data['display_name'],
            'description'  => $data['description'] ?? null,
        ]);

        if (isset($data['permissions'])) {
            $this->syncPermissions($role, $data['permissions']);
        }

        return $role->load('permissions');
    }

    public function delete(Role $role): void
    {
        $this->ensureRoleOwnedByMerchant($role);
        $this->ensureRoleNotDefault($role);
        $this->ensureRoleNotInUse($role);

        $role->delete();
    }

    // -------------------------------------------------------
    // Permission Sync
    // -------------------------------------------------------

    public function syncPermissions(Role $role, array $permissionNames): void
    {
        $permissions = Permission::whereIn('name', $permissionNames)
          ->where('guard_name', 'merchant')
          ->get();

        $role->syncPermissions($permissions);
    }

    // -------------------------------------------------------
    // Default Roles (dipanggil saat merchant baru daftar)
    // -------------------------------------------------------

    public function createDefaultRoles(int $merchantId): void
    {
        $allPermissions = Permission::where('guard_name', 'merchant')
                                    ->pluck('name')
                                    ->toArray();

        $defaultRoles = $this->defaultRolesConfig($allPermissions);

        foreach ($defaultRoles as $config) {
            $role = Role::create([
                'name'         => $config['name'],
                'display_name' => $config['display_name'],
                'description'  => $config['description'],
                'guard_name'   => 'merchant',
                'merchant_id'  => $merchantId,
                'is_default'   => true,
            ]);

            $role->syncPermissions($config['permissions']);
        }
    }


    // -------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------

    private function ensureRoleOwnedByMerchant(Role $role): void
    {
        if ($role->merchant_id !== MerchantContext::id()) {
            throw new \Exception('Yahh... Role ngga ketemu.');
        }
    }

    private function ensureRoleNotDefault(Role $role): void
    {
        if ($role->is_default) {
            throw new \Exception(
                'Oppss... kamu ngga bisa hapus default sistem.'
            );
        }
    }

    private function ensureRoleNotInUse(Role $role): void
    {
        if ($role->users()->count() > 0) {
            throw new \Exception(
                'Role udah diassign ke user jadi ngga bisa dihapus.'
            );
        }
    }

    private function defaultRolesConfig(array $allPermissions): array
    {
        return [
            [
                'name'         => 'owner',
                'display_name' => 'Owner',
                'description'  => 'Akses penuh ke semua fitur.',
                'permissions'  => [],
            ],
            [
                'name'         => 'manager',
                'display_name' => 'Manager',
                'description'  => 'Akses ke semua fitur kecuali pengaturan kritis.',
                'permissions'  => array_filter(
                    $allPermissions,
                    fn($p) => !in_array($p, [
                        'core.setting.update',
                        'core.role.delete',
                        'core.user.delete',
                    ])
                ),
            ],
            [
                'name'         => 'kasir',
                'display_name' => 'Kasir',
                'description'  => 'Akses ke transaksi penjualan.',
                'permissions'  => [
                    'core.dashboard.view',
                    'product.view',
                    'product.category.view',
                    'customer.view',
                    'customer.create',
                    'customer.update',
                    'sales.view',
                    'sales.create',
                    'sales.discount',
                    'inventory.view',
                ],
            ],
            [
                'name'         => 'gudang',
                'display_name' => 'Gudang',
                'description'  => 'Akses ke inventory dan pembelian.',
                'permissions'  => [
                    'core.dashboard.view',
                    'product.view',
                    'product.category.view',
                    'supplier.view',
                    'supplier.create',
                    'supplier.update',
                    'purchase.view',
                    'purchase.create',
                    'purchase.update',
                    'inventory.view',
                    'inventory.adjustment.view',
                    'inventory.adjustment.create',
                    'inventory.transfer.view',
                    'inventory.transfer.create',
                    'inventory.report',
                    'core.warehouse.view',
                ],
            ],
        ];
    }
}