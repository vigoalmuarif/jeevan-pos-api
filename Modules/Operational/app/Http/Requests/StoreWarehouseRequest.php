<?php

namespace Modules\Operational\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class StoreWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
             'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique('warehouses')
                    ->where('merchant_id', MerchantContext::id()),
            ],
             'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('warehouses')
                    ->where('merchant_id', MerchantContext::id()),
            ],
            'address' => 'required|string',
            'phone'   => 'nullable|string|max:20',
            'is_active'  => 'nullable|boolean',
            'region_ids' => ['required', 'array', 'min:1'],
            'region_ids.*' => [
                'required',
                'integer',
                'distinct',
                Rule::exists('regions', 'id')->where(
                    fn ($query) => $query->where('merchant_id', MerchantContext::id())->where('is_active', true)
                ),
            ],
        ];
    }

    
    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi',
            'name.unique' => 'Nama sudah digunakan',
            'code.required' => 'Kode wajib diisi',
            'code.unique' => 'Kode sudah digunakan',
            'address.required' => 'Alamat wajib diisi',
            'region_ids.required' => 'Pilih minimal satu regional yang dilayani gudang',
            'region_ids.min' => 'Pilih minimal satu regional yang dilayani gudang',
            'region_ids.*.exists' => 'Regional tidak aktif atau bukan milik merchant ini',
        ];
    }
}