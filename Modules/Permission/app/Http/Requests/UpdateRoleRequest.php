<?php

namespace Modules\Permission\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class UpdateRoleRequest extends FormRequest
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
                Rule::unique('roles')
                    ->where('merchant_id', MerchantContext::id())
                    ->where('guard_name', 'merchant')
                    ->ignore($this->role->id),
            ],
            'display_name' => 'required|string|max:100',
            'description'  => 'nullable|string|max:255',
            'permissions'  => 'nullable|array',
            'permissions.*'=> 'string|exists:permissions,name',
        ];
    }
}