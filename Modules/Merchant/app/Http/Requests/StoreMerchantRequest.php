<?php

namespace Modules\Merchant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Data Merchant
            'name' => 'required|string|max:100',
            'industry_package_code' => 'required|string|max:100',
            'email' => 'nullable|email|max:100|unique:merchants,email',
            'phone' => 'nullable|string|max:20',
            'address' => 'nullable|string',
            'city' => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'country' => 'nullable|string|max:2',
            'timezone' => 'nullable|string|max:50',
            'currency' => 'nullable|string|max:3',
            'locale' => 'nullable|string|max:5',
            'status' => 'nullable|in:active,trial',
        ];
    }

    public function messages(): array
    {
        return [
            // merchant
            'name.required' => 'Nama wajib diisi.',
            'industry_package_code.required' => 'Jenis bisnis wajib diisi.',
            'slug.unique' => 'URL sudah digunakan.',
            'slug.regex' => 'Gak boleh mengandung spasi.',
            'email.email' => 'Email wajib diisi.',
            'email.unique' => 'Email sudah digunakan.',
        ];
    }
}