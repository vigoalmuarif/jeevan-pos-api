<?php

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Modules\Core\Helpers\MerchantContext;
use Modules\Core\Traits\ApiResponse;
use Modules\Merchant\Models\Merchant;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class ResolveMerchant
{
    use ApiResponse;

    public function handle(Request $request, Closure $next): Response
    {

        $user = $request->user('merchant');

        if (!$user) {
            return $next($request);  // lanjut tanpa context
        }

        $merchant = $user->merchant;

        if (!$merchant) {
            throw new AuthorizationException('User tidak terikat ke Merchant manapun');
        }

        if ($user && $merchant) {
            MerchantContext::set($merchant);
            app(PermissionRegistrar::class)->setPermissionsTeamId($user->merchant_id);
        }

        return $next($request);
    }
}