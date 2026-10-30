<?php

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Traits\ApiResponse;
use Symfony\Component\HttpFoundation\Response;

class CheckMerchantActive
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $merchant = MerchantContext::get();

        if (!$merchant) {
            return $this->error('Merchant context tidak ditemukan.', 403, null, 'MERCHANT_CONTEXT_REQUIRED');
        }

        if (in_array($merchant->status, ['inactive', 'suspended'], true)) {
            $message = match ($merchant->status) {
                'inactive'  => 'Oopps... Toko kamu sudah tidak aktif',
                'suspended' => 'Waduh.... Toko kamu telah disuspend.',
                default     => 'Merchant tidak aktif.',
            };

            return $this->error($message, 403, ['status' => $merchant->status], 'MERCHANT_INACTIVE');
        }

        return $next($request);
    }
}