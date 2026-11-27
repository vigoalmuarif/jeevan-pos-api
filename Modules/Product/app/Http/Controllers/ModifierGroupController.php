<?php

declare(strict_types=1);

namespace Modules\Product\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Product\Http\Requests\StoreModifierGroupRequest;
use Modules\Product\Http\Requests\SyncProductModifiersRequest;
use Modules\Product\Http\Requests\UpdateModifierGroupRequest;
use Modules\Product\Services\ModifierGroupService;
use Modules\Product\Transformers\ModifierGroupResource;

class ModifierGroupController extends Controller
{
    public function __construct(
        private readonly ModifierGroupService $service,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $paginator = $this->service->list($request->only(['search', 'is_active', 'per_page']));

        return response()->json([
            'data' => ModifierGroupResource::collection($paginator->items()),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        ]);
    }

    public function show(int $modifierGroup): JsonResponse
    {
        return response()->json([
            'data' => new ModifierGroupResource($this->service->show($modifierGroup)),
        ]);
    }

    public function store(StoreModifierGroupRequest $request): JsonResponse
    {
        $group = $this->service->create($request->validated());

        return response()->json([
            'data' => new ModifierGroupResource($group),
            'message' => 'Grup modifier berhasil dibuat.',
        ], 201);
    }

    public function update(UpdateModifierGroupRequest $request, int $modifierGroup): JsonResponse
    {
        $group = $this->service->update($modifierGroup, $request->validated());

        return response()->json([
            'data' => new ModifierGroupResource($group),
            'message' => 'Grup modifier berhasil diperbarui.',
        ]);
    }

    public function destroy(int $modifierGroup): JsonResponse
    {
        $this->service->delete($modifierGroup);

        return response()->json([
            'message' => 'Grup modifier berhasil dihapus.',
        ]);
    }

    public function syncProductGroups(SyncProductModifiersRequest $request, int $product): JsonResponse
    {
        $groups = $this->service->syncProductGroups(
            $product,
            $request->validated('modifier_group_ids', []),
        );

        return response()->json([
            'data' => ModifierGroupResource::collection($groups),
            'message' => 'Modifier produk berhasil diperbarui.',
        ]);
    }
}
