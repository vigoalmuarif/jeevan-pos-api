<?php

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Helpers\MerchantContext;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleAccess
{
    public function handle(
        Request $request,
        Closure $next,
        string $moduleCode
    ): Response {
        $merchant = MerchantContext::getOrFail();

        if (!$merchant->hasModule($moduleCode)) {
            return response()->json([
                'success' => false,
                'message' => 'Oopss... paket kamu ngga mencangkup modul ini',
                'code'    => 'MODULE_NOT_ACCESSIBLE',
            ], 403);
        }

        return $next($request);
    }
}