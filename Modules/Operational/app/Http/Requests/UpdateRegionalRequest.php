<?php

namespace Modules\Operational\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class UpdateRegionalRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        $merchantId = MerchantContext::id();
        $region = $this->route('regional');

        return [
            'name' => ['required', 'string', 'max:255'],
            'code' => [
                'required',
                'string',
                'max:255',
                Rule::unique('regions', 'code')->where('merchant_id', $merchantId)->ignore($region?->id),
            ],
            'type' => ['required', Rule::in(['province', 'city', 'custom'])],
            'parent_region_id' => [
                'nullable',
                'integer',
                Rule::exists('regions', 'id')->where('merchant_id', $merchantId),
            ],
            'is_active' => ['sometimes', 'boolean'],
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
