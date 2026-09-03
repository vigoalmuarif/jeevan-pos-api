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
            'branch_id' => 'required',
            'address' => 'required|string',
            'phone'   => 'nullable|string|max:20',
            'is_main' => 'nullable|boolean',
            'is_active'  => 'nullable|boolean',
        ];
    }

    
    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi',
            'name.unique' => 'Nama sudah digunakan',
            'code.required' => 'Kode wajib diisi',
            'code.unique' => 'Kode sudah digunakan',
            'branch_id.required' => 'Cabang wajib dipilih',
            'address.required' => 'Alamat wajib diisi',
        ];
    }
}