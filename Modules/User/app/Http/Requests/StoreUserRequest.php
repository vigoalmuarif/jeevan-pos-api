<?php

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Core\Helpers\MerchantContext;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:100',
            'username' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9_\-]+$/',
                // Unik per merchant
                Rule::unique('users')
                    ->where('merchant_id', MerchantContext::id()),
            ],
            'email' => [
                'required',
                'email',
                // Email unik per merchant
                Rule::unique('users')
                    ->where('merchant_id', MerchantContext::id()),
            ],
            'phone' => 'nullable|string|max:20',
            'password' => 'required|string|min:8|confirmed',
            'is_active' => 'nullable|boolean',
            'role' => [
                'required',
                'string',
                Rule::exists('roles', 'name')
                    ->where('merchant_id', MerchantContext::id())
                    ->where('guard_name', 'merchant'),
            ],
            'is_all_branches' => 'nullable|boolean',
            'branch_ids' => [
                'nullable',
                'array',
                Rule::requiredIf(
                    fn() => !$this->boolean('is_all_branches')
                ),
            ],
            'branch_ids.*' => 'integer|exists:branches,id',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'Nama wajib diisi',
            'username.required' => 'Username wajib diisi',
            'username.regex'    => 'Username ngga boleh ada spasi',
            'username.unique'   => 'Username sudah digunakan',
            'password.required' => 'Password wajib diisi',
            'password.confirmed' => 'Harap konfirmasi password',
            'role.required'     => 'Role wajib diisi',
            'branch_ids.required' => 'Cabang wajib diisi ketika semua cabang dimatikan.',
        ];
    }
}