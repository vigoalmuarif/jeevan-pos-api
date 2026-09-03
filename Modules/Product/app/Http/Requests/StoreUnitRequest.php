<?php

namespace Modules\Product\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class StoreUnitRequest extends FormRequest
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
                Rule::unique('units', 'name')
                    ->where(function($q) {
                        $q->where('merchant_id', MerchantContext::id())
                            ->orWhere('is_system', true);
                    }),
            ],
            'code' => [
                'required',
                'max:50',
                Rule::unique('units', 'code')
                    ->where(function($q) {
                        $q->where('merchant_id', MerchantContext::id())
                            ->orWhere('is_system', true);
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
                'unique' => 'Nama satuan sudah ada',
            ],
            'code' => [
                'required' => 'Kode wajib diisi.',
                'max' => 'Maksimal 50 karakter',
                'unique' => 'Kode satuan sudah ada',
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
