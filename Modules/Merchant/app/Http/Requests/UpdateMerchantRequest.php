<?php

namespace Modules\Merchant\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateMerchantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name'     => 'required|string|max:100',
            'email'    => [
                'nullable',
                'email',
                'max:100',
                Rule::unique('merchants', 'email')
                    ->ignore($this->merchant->id),
            ],
            'phone'    => 'nullable|string|max:20',
            'address'  => 'nullable|string',
            'city'     => 'nullable|string|max:100',
            'province' => 'nullable|string|max:100',
            'country'  => 'nullable|string|max:2',
            'timezone' => 'nullable|string|max:50',
            'currency' => 'nullable|string|max:3',
            'locale'   => 'nullable|string|max:5',
        ];
    }

    
    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'email.email' => 'Email wajib diisi.',
            'email.unique' => 'Email sudah digunakan.',
        ];
    }
}