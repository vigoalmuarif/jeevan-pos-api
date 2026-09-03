<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class StoreBrandRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'max:50',
                Rule::unique('brands', 'name')
                    ->where(function ($q) {
                        $q->where('merchant_id', MerchantContext::id());
                    }),
            ],

        ];
    }

    public function messages(): array
    {
        return [
            'name' => [
                'required' => 'Nama wajib diisi.',
                'max' => 'Maksimal 50 karakter',
                'unique' => 'Nama merek sudah ada',
            ],

        ];
    }

    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }
}
