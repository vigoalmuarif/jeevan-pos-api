<?php

namespace Modules\User\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Operational\Models\Branch;
use Modules\Core\Abstracts\BaseService;
use Modules\Core\Helpers\MerchantContext;
use Modules\Permission\Models\Role;
use Modules\User\Models\User;

class UserService extends BaseService
{
    public function list(array $filters = []): LengthAwarePaginator
    {
        return User::with(['roles', 'branches'])
            ->when(
                isset($filters['search']),
                fn($q) => $q->where(function ($q) use ($filters) {
                    $q->where('name', 'like', "%{$filters['search']}%")
                      ->orWhere('email', 'like', "%{$filters['search']}%");
                })
            )
            ->when(
                isset($filters['is_active']),
                fn($q) => $q->where('is_active', $filters['is_active'])
            )
            ->when(
                isset($filters['role']),
                fn($q) => $q->whereHas('roles', fn($q) =>
                    $q->where('name', $filters['role'])
                )
            )
            ->latest()
            ->paginate($filters['per_page'] ?? 15);
    }

    public function create(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $user = User::create([
                'merchant_id'     => MerchantContext::id(),
                'name'            => $data['name'],
                'username'        => Str::lower(Str::slug($data['username'], '_')),
                'email'           => $data['email'],
                'phone'           => $data['phone'] ?? null,
                'password'        => Hash::make($data['password']),
                'is_active'       => $data['is_active'] ?? true,
                'is_all_branches' => $data['is_all_branches'] ?? false,
            ]);

            // Assign role
            if (!empty($data['role'])) {
                $this->assignRole($user, $data['role']);
            }

            // Assign branch
            if (!$user->is_all_branches && !empty($data['branch_ids'])) {
                $this->syncBranches($user, $data['branch_ids']);
            }

            return $user->load(['roles', 'branches']);
        });
    }

    public function update(User $user, array $data): User
    {
        return DB::transaction(function () use ($user, $data) {
            $user->update([
                'name'            => $data['name'],
                'username'        => $data['username'],
                'phone'           => $data['phone'] ?? null,
                'is_active'       => $data['is_active'],
                'is_all_branches' => $data['is_all_branches'] ?? false,
            ]);

            // Update role
            if (!empty($data['role'])) {
                $this->assignRole($user, $data['role']);
            }

            // Update branch
            if (!$user->is_all_branches && isset($data['branch_ids'])) {
                $this->syncBranches($user, $data['branch_ids']);
            }

            // Jika is_all_branches true, hapus semua branch assignment
            if ($user->is_all_branches) {
                $user->branches()->detach();
            }

            return $user->load(['roles', 'branches']);
        });
    }

    public function delete(User $user): void
    {
        $this->ensureNotDeletingSelf($user);
        $this->ensureNotLastOwner($user);

        $user->delete();
    }

    public function resetPassword(User $user, string $password): void
    {
        $user->update([
            'password' => Hash::make($password),
        ]);
    }

    // -------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------

    public function assignRole(User $user, string $roleName): void
    {
        $role = Role::forMerchant(MerchantContext::id())
                    ->where('name', $roleName)
                    ->firstOrFail();

        // Spatie syncRoles — replace semua role lama
        $user->syncRoles([$role]);
    }

    public function syncBranches(User $user, array $branchIds): void
    {
        // Pastikan semua branch milik merchant ini
        $validBranchIds = Branch::where('merchant_id', MerchantContext::id())
                                ->whereIn('id', $branchIds)
                                ->pluck('id')
                                ->toArray();

        if (count($validBranchIds) !== count($branchIds)) {
            throw ValidationException::withMessages([
                'branch_ids' => 'Ada cabang yang ga valid nih 😕',
            ]);
        }

        $user->branches()->sync($validBranchIds);
    }

    private function ensureNotDeletingSelf(User $user): void
    {
        if ($user->id === request()->user('merchant')->id) {
            throw ValidationException::withMessages([
                'user' => 'Kamu gak boleh hapus akunmu sendiri 😏',
            ]);
        }
    }

    private function ensureNotLastOwner(User $user): void
    {
        $isOwner = $user->hasRole('owner');

        if (!$isOwner) {
            return;
        }

        $ownerCount = User::whereHas('roles', fn($q) =>
            $q->where('name', 'owner')
              ->where('merchant_id', MerchantContext::id())
        )->count();

        if ($ownerCount <= 1) {
            throw ValidationException::withMessages([
                'user' => 'Kamu gak boleh hapus akun owner 😡',
            ]);
        }
    }
}