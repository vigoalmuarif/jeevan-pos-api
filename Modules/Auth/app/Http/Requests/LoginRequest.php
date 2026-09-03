<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'username'     => 'required|string',
            'password' => 'required|string',
            'branch_id' => 'nullable|integer|exists:branches,id',
        ];
    }

    public function messages(): array
    {
        return [
            'username.required' => 'Username wajib diisi.',
            'password.required' => 'Password wajib diisi.',
            'branch_id.exists' => 'Cabang tidak ditemukan',
        ];
    }
}