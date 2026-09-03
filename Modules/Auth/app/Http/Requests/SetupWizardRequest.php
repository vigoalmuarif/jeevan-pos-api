<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SetupWizardRequest extends FormRequest
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
        ];
    }

    public function messages(): array
    {
        return [
            // merchant
            'name.required' => 'Nama wajib diisi.',
            'industry_package_code.required' => 'Jenis bisnis wajib diisi.',
        ];
    }
}
