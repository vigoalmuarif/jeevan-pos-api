<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class UpdateCategoryRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'max:50',
                Rule::unique('categories', 'name')
                    ->where(function ($q) {
                        $q->where('merchant_id', MerchantContext::id());
                    })
                    ->ignore($this->category->id),
            ],

        ];
    }

    public function messages(): array
    {
        return [
            'name' => [
                'required' => 'Nama wajib diisi.',
                'max' => 'Maksimal 50 karakter',
                'unique' => 'Nama kategori sudah ada',
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
