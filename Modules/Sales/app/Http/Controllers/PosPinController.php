<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Sales\Http\Requests\UnlockPosPinWithPasswordRequest;
use Modules\Sales\Http\Requests\UpdatePosPinRequest;
use Modules\Sales\Http\Requests\VerifyPosPinRequest;
use Modules\Sales\Services\PosPinService;
use Modules\User\Models\User;

class PosPinController extends Controller
{
    public function __construct(
        private readonly PosPinService $posPinService,
    ) {}

    public function update(UpdatePosPinRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user('merchant');
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        $result = $this->posPinService->updatePin($user, $request->validated(), $request);

        return response()->json([
            'data' => $result,
            'message' => $result['pos_pin_enabled']
                ? 'Pengaturan PIN kasir POS berhasil diaktifkan.'
                : 'PIN kasir POS berhasil dinonaktifkan.',
        ]);
    }

    public function lock(Request $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user('merchant');
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        $this->posPinService->markSessionLocked($request, $user);

        return response()->json([
            'data' => ['locked' => true],
            'message' => 'Layar kasir POS berhasil dikunci.',
        ]);
    }

    public function verify(VerifyPosPinRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user('merchant');
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        $result = $this->posPinService->verifyPin($user, (string) $request->validated('pin'), $request);

        return response()->json([
            'data' => $result,
            'message' => 'PIN terverifikasi.',
        ]);
    }

    public function unlockWithPassword(UnlockPosPinWithPasswordRequest $request): JsonResponse
    {
        /** @var User|null $user */
        $user = $request->user('merchant');
        abort_unless($user instanceof User, 401, 'Unauthenticated.');

        $validated = $request->validated();
        $result = $this->posPinService->unlockWithPassword(
            user: $user,
            password: (string) $validated['password'],
            disablePin: (bool) ($validated['disable_pin'] ?? false),
            request: $request,
        );

        return response()->json([
            'data' => $result,
            'message' => 'Layar kasir POS berhasil dibuka.',
        ]);
    }
}
