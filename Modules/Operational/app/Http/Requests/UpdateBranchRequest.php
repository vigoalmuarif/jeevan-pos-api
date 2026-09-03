<?php

namespace Modules\Operational\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class UpdateBranchRequest extends FormRequest
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
                Rule::unique('branches')
                    ->where('merchant_id', MerchantContext::id())
                    ->ignore($this->branch->id),
            ],
            'code' => [
                'required',
                'string',
                'max:20',
                Rule::unique('branches')
                    ->where('merchant_id', MerchantContext::id())
                    ->ignore($this->branch->id),
            ],
            'email' => 'nullable|email|max:100',
            'phone' => 'nullable|string|max:20',
            'address' => 'required|string',
            'province_code' => 'required|string|max:5',
            'regency_code' => 'required|string|max:10',
            'district_code' => 'required|string|max:15',
            'village_code' => 'required|string|max:20',
            'postal_code' => 'nullable|string|max:10',
            'receipt_header' => 'nullable|string',
            'receipt_footer' => 'nullable|string',
            'is_main' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama cabang wajib diisi',
            'name.unique' => 'Nama cabang sudah digunakan',
            'code.required' => 'Kode wajib diisi',
            'code.unique' => 'Kode sudah digunakan',
            'email.email' => 'Format email gak valid',
            'address.required' => 'Alamat wajib diisi',
            'province_code.required' => 'Provinsi wajib diisi',
            'regency_code.required' => 'Kabupaten/Kota wajib diisi',
            'district_code.required' => 'Kecamatan wajib diisi',
            'village_code.required' => 'Desa/Kelurahan wajib diisi',
        ];
    }
}