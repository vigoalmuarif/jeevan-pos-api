<?php

namespace Modules\Operational\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Operational\Models\Branch;
use Modules\Core\Helpers\BranchContext;
use Modules\Core\Helpers\MerchantContext;
use Symfony\Component\HttpFoundation\Response;

class ResolveBranch
{
    public function handle(Request $request, Closure $next): Response
    {
        $merchant = MerchantContext::getOrFail();
        $user = $request->user();
        $branchId = session('branch_id');

        // Jika belum ada branch di session
        if (!$branchId) {
            $branchId = $this->resolveDefaultBranch($user, $merchant->id);

            // Jika berhasil resolve, simpan ke session
            if ($branchId) {
                session(['branch_id' => $branchId]);
            } else {
                return response()->json([
                    'success' => false,
                    'message' => '🚪 Tok tok tok... ternyata gak ada cabangnya di sini.',
                    'code' => 'BRANCH_NOT_FOUND',
                ], 403);
            }
        }

        // Validasi branch
        $branch = Branch::select('id')
            ->where('id', $branchId)
            ->where('merchant_id', $merchant->id)
            ->where('is_active', true)
            ->first();

        if (!$branch) {
            // Clear session branch yang tidak valid
            session()->forget('branch_id');

            return response()->json([
                'success' => false,
                'message' => '🗺️ GPS bilang cabangnya gak ketemu. Kalau ketemu pun kayaknya lagi tutup.',
                'code' => 'BRANCH_INVALID',
            ], 403);
        }

        // Validasi akses user ke branch
        if (!$user->canAccessBranch($branch->id)) {
            return response()->json([
                'success' => false,
                'message' => 'Oopss... kamu ngga boleh akses cabang ' . str_replace('cabang', '', $branch->name),
                'code' => 'BRANCH_ACCESS_DENIED',
            ], 403);
        }

        BranchContext::set($branch);

        return $next($request);
    }

    // -------------------------------------------------------
    // Private Helper
    // -------------------------------------------------------

    private function resolveDefaultBranch(
        $user,
        int $merchantId
    ): ?int {
        // Ambil branch yang bisa diakses user
        if ($user->is_all_branches) {
            // Ambil branch utama merchant
            $branch = Branch::select('id')
                ->where('merchant_id', $merchantId)
                ->where('is_active', true)
                ->where('is_main', true)
                ->first();

            return $branch?->id;
        }

        // Ambil branch pertama yang di-assign ke user
        $branch = $user->branches()
            ->where('is_active', true)
            ->first();

        return $branch?->id;
    }
}