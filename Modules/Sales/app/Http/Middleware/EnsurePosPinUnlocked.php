<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Sales\Services\PosPinService;
use Modules\User\Models\User;
use Symfony\Component\HttpFoundation\Response;

class EnsurePosPinUnlocked
{
    public function __construct(
        private readonly PosPinService $posPinService,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user('merchant');

        if (! $user instanceof User) {
            return $next($request);
        }

        if (! $user->pos_pin_enabled || ! $user->hasPosPin()) {
            return $next($request);
        }

        if (! $this->posPinService->isSessionUnlocked($request, $user)) {
            return response()->json([
                'success' => false,
                'message' => 'Layar kasir POS terkunci. Masukkan PIN untuk melanjutkan transaksi.',
                'error' => [
                    'code' => 'POS_PIN_LOCKED',
                    'message' => 'Layar kasir POS terkunci. Masukkan PIN untuk melanjutkan transaksi.',
                ],
            ], 423);
        }

        $this->posPinService->touchSessionActivity($request, $user);

        return $next($request);
    }
}
