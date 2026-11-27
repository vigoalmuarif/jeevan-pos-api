<?php

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Traits\ApiResponse;
use Modules\Merchant\Models\Merchant;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;

class ResolveMerchant
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {
        $merchantParam = $request->route('merchant');
        $merchant = null;

        if ($merchantParam !== null) {
            if ($merchantParam instanceof Merchant) {
                $merchant = $merchantParam;
            } elseif (is_numeric($merchantParam)) {
                $merchant = Merchant::find($merchantParam);
            } elseif (is_string($merchantParam) && trim($merchantParam) !== '') {
                $merchant = Merchant::where('slug', $merchantParam)
                    ->orWhere('ulid', $merchantParam)
                    ->first();
            }

            if (!$merchant) {
                return $this->error('Merchant tidak ditemukan.', 404, null, 'NOT_FOUND');
            }

            if ($request->route()) {
                $request->route()->setParameter('merchant', $merchant);
            }

            $user = $request->user('merchant') ?? $request->user();
            if ($user && isset($user->merchant_id) && (int) $user->merchant_id !== (int) $merchant->id) {
                return $this->error('Anda tidak memiliki akses ke merchant ini.', 403, null, 'UNAUTHORIZED_MERCHANT_ACCESS');
            }
        } else {
            $user = $request->user('merchant') ?? $request->user();

            if ($user) {
                if (empty($user->merchant_id)) {
                    return $this->error('User tidak terikat ke Merchant manapun.', 403, null, 'UNAUTHORIZED_MERCHANT_ACCESS');
                }

                $merchant = $user->merchant ?? Merchant::find($user->merchant_id);

                if (!$merchant) {
                    return $this->error('Merchant tidak ditemukan.', 404, null, 'NOT_FOUND');
                }
            } else {
                return $next($request);
            }
        }

        if ($merchant) {
            if (in_array($merchant->status, ['inactive', 'suspended'], true)) {
                $message = match ($merchant->status) {
                    'inactive'  => 'Oopps... Toko kamu sudah tidak aktif',
                    'suspended' => 'Waduh.... Toko kamu telah disuspend.',
                    default     => 'Merchant tidak aktif.',
                };

                return $this->error($message, 403, ['status' => $merchant->status], 'MERCHANT_INACTIVE');
            }

            MerchantContext::set($merchant);

            if (class_exists(PermissionRegistrar::class)) {
                app(PermissionRegistrar::class)->setPermissionsTeamId($merchant->id);
            }
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        MerchantContext::clear();
    }
}