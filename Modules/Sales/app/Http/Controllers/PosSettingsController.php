<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Modules\Sales\Http\Requests\UpdateBranchPosSettingRequest;
use Modules\Sales\Services\PosConfigResolver;

class PosSettingsController extends Controller
{
    public function __construct(
        private readonly PosConfigResolver $configResolver,
    ) {}

    public function update(UpdateBranchPosSettingRequest $request, int $branch): JsonResponse
    {
        $config = $this->configResolver->updateBranchSetting($branch, $request->validated());

        return response()->json([
            'data' => $config,
            'message' => 'Pengaturan POS cabang berhasil diperbarui.',
        ]);
    }
}
