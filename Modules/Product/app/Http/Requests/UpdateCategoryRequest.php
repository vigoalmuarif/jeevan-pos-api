<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class UpdateCategoryRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => strtoupper(trim((string) $this->input('code')))]);
        }
    }

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
            'code' => [
                'required',
                'string',
                'max:50',
                Rule::unique('categories', 'code')
                    ->where('merchant_id', MerchantContext::id())
                    ->ignore($this->category->id),
            ],
            'description' => ['nullable', 'string'],
            'is_active' => ['sometimes', 'boolean'],
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
            'code.required' => 'Kode kategori wajib diisi.',
            'code.unique' => 'Kode kategori sudah digunakan.',
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
