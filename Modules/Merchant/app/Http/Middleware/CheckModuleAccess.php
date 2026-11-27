<?php

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleAccess
{
    use ApiResponse;

    public function handle(
        Request $request,
        Closure $next,
        string $moduleCode
    ): Response {
        $merchant = MerchantContext::get();

        if (!$merchant) {
            return $this->error('Merchant context tidak ditemukan.', 403, null, 'MERCHANT_CONTEXT_REQUIRED');
        }

        if (!$merchant->hasModule($moduleCode)) {
            return $this->error(
                'Oopss... paket kamu ngga mencangkup modul ini',
                403,
                ['module' => $moduleCode],
                'MODULE_NOT_ENTITLED'
            );
        }

        return $next($request);
    }
}