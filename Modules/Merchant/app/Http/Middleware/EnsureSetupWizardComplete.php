<?php

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Traits\ApiResponse;

class EnsureSetupWizardComplete
{
    use ApiResponse;
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user('merchant');

        if ($user && !$user->merchant_id) {
            return $this->error('Selesaikan setup wizard terlebih dahulu.', 409);
        }

        return $next($request);
    }
}
