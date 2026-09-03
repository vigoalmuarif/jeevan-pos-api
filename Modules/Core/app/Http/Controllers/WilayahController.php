<?php

namespace Modules\Core\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Core\Abstracts\BaseController;
use Modules\Core\Models\Wilayah;

class WilayahController extends BaseController
{
    // GET /api/wilayah?parent_code=&search=
    public function index(Request $request): JsonResponse
    {
        $parentKode = $request->string('parent_code', '')->toString();
        $search     = $request->string('search', '')->toString();

        $data = Wilayah::query()
            ->childrenOf($parentKode)
            ->when($search, fn($q) => $q->where('nama', 'ilike', "%{$search}%"))
            ->orderBy('nama')
            ->limit(50)
            ->get(['kode', 'nama']);

        return $this->success($data);
    }

    // GET /api/wilayah/search?search=menteng&per_page=15
    public function search(Request $request): JsonResponse
    {
        $search  = $request->string('search', '')->toString();
        $perPage = $request->integer('per_page', 15);

        // Kelurahan = code length 13 (format: XX.XX.XX.XXXX)
        $village = Wilayah::query()
            ->whereRaw('LENGTH(kode) >= 13')
            ->when($search, fn($q) => $q->where('nama', 'ilike', "%{$search}%"))
            ->orderBy('nama')
            ->limit($perPage)
            ->get(['kode', 'nama']);

        // Collect semua ancestor codes sekaligus — hindari N+1
        $ancestorKodes = $village->flatMap(function (Wilayah $item) {
            $parts = explode('.', $item->kode);
            return [
                $parts[0],                                    // provinsi
                "{$parts[0]}.{$parts[1]}",                   // kabupaten
                "{$parts[0]}.{$parts[1]}.{$parts[2]}",       // kecamatan
            ];
        })->unique()->values();

        $ancestors = Wilayah::whereIn('kode', $ancestorKodes)
            ->get(['kode', 'nama'])
            ->keyBy('kode');

        // Gabungkan kelurahan dengan ancestor-nya
        $result = $village->map(function (Wilayah $item) use ($ancestors) {
            $parts = explode('.', $item->kode);

            $province  = $ancestors[$parts[0]] ?? null;
            $regency = $ancestors["{$parts[0]}.{$parts[1]}"] ?? null;
            $district = $ancestors["{$parts[0]}.{$parts[1]}.{$parts[2]}"] ?? null;

            return [
                'village_code' => $item->kode,
                'village_name' => $item->nama,
                'district_code' => $district?->kode,
                'district_name' => $district?->nama,
                'regency_code' => $regency?->kode,
                'regency_name' => $regency?->nama,
                'province_code'  => $province?->kode,
                'province_name'  => $province?->nama,
                // Label lengkap untuk display di combobox option
                'label'          => collect([
                    $item->nama,
                    $district?->nama,
                    $regency?->nama,
                    $province?->nama,
                ])->filter()->implode(', '),
            ];
        });

        return $this->success($result);
    }

    // GET /api/wilayah/ancestors?code=35.07.01.2001
    // Digunakan untuk auto-fill saat edit form
    public function ancestors(Request $request): JsonResponse
    {
        $code = $request->string('kode')->toString();

        if (blank($code)) {
            return $this->success([]);
        }

        $codes = $this->resolveAncestorKodes($code);

        $wilayah = Wilayah::whereIn('code', $codes)
            ->get(['code', 'nama'])
            ->keyBy('code');

        return $this->success([
            [
                'province'  => $wilayah[$codes['province']] ?? null,
                'regency' => $wilayah[$codes['regency']] ?? null,
                'district' => $wilayah[$codes['district']] ?? null,
                'village' => $wilayah[$codes['village']] ?? null,
            ],
        ]);
    }

    // Derive semua ancestor code dari code village
    private function resolveAncestorKodes(string $code): array
    {
        $parts = explode('.', $code);

        return [
            'province'  => $parts[0] ?? null,
            'regency' => isset($parts[1]) ? "{$parts[0]}.{$parts[1]}" : null,
            'district' => isset($parts[2]) ? "{$parts[0]}.{$parts[1]}.{$parts[2]}" : null,
            'village' => count($parts) === 4 ? $code : null,
        ];
    }
}
