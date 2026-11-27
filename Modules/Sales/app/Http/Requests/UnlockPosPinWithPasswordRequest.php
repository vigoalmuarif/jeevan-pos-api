<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UnlockPosPinWithPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'max:255'],
            'disable_pin' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.required' => 'Password akun wajib diisi.',
        ];
    }
}
