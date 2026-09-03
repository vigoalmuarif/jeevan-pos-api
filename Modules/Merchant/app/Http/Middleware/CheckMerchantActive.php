<?php

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Helpers\MerchantContext;
use Symfony\Component\HttpFoundation\Response;

class CheckMerchantActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $merchant = MerchantContext::getOrFail();

        if (in_array($merchant->status, ['inactive', 'suspended'])) {
            return response()->json([
                'success' => false,
                'message' => match($merchant->status) {
                    'inactive'  => 'Oopps... Toko kamu sudah tidak aktif',
                    'suspended' => 'Waduh.... Toko kamu telah disuspend.',
                },
            ], 403);
        }

        return $next($request);
    }
}