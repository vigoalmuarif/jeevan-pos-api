<?php

namespace Modules\Product\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Core\Helpers\MerchantContext;
use Modules\Product\Models\PriceList;

class PriceListService
{
    public function all()
    {
        return PriceList::query()->where('merchant_id', $this->merchantId())->orderByDesc('is_default')->orderBy('name')->get();
    }

    public function find(int $id): PriceList
    {
        return PriceList::query()->where('merchant_id', $this->merchantId())->findOrFail($id);
    }

    public function create(array $data): PriceList
    {
        return DB::transaction(function () use ($data) {
            $merchantId = $this->merchantId();
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()->where('merchant_id', $merchantId)->update(['is_default' => false]);
            }
            return PriceList::query()->create([
                ...$data,
                'merchant_id' => $merchantId,
                'is_default' => $data['is_default'] ?? false,
                'is_active' => $data['is_active'] ?? true,
            ]);
        });
    }

    public function update(int $id, array $data): PriceList
    {
        return DB::transaction(function () use ($id, $data) {
            $merchantId = $this->merchantId();
            $list = PriceList::query()->where('merchant_id', $merchantId)->lockForUpdate()->findOrFail($id);
            if (($data['is_default'] ?? false) === true) {
                PriceList::query()->where('merchant_id', $merchantId)->whereKeyNot($id)->update(['is_default' => false]);
            }
            if (($data['is_active'] ?? null) === false && $list->is_default && ! ($data['is_default'] ?? false)) {
                $list->is_default = false;
            }
            $list->fill($data)->save();
            return $list->refresh();
        });
    }

    public function deactivate(int $id): void
    {
        $list = $this->find($id);
        if ($list->prices()->where('is_active', true)->exists()) {
            throw ValidationException::withMessages(['price_list' => 'Daftar harga masih dipakai oleh harga aktif. Nonaktifkan atau hapus harga terkait terlebih dahulu.']);
        }
        $list->is_active = false;
        $list->is_default = false;
        $list->save();
    }

    private function merchantId(): int
    {
        abort_unless(MerchantContext::check(), 403, 'Merchant context is required.');
        return (int) MerchantContext::id();
    }
}
