<?php

namespace Modules\Auth\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Data Owner
            'name'     => 'required|string|max:100',
            'phone'     => 'required|string|max:100|unique:users,phone',
            'email'    => 'required|email|max:100|unique:users,email',
            'username' => [
                'required',
                'string',
                'max:50',
                'regex:/^[a-z0-9_\-]+$/',
                'unique:users,username'
            ],
            'password' => [
                'required',
                'confirmed',
                Password::min(6)
                        ->letters()
                        ->numbers(),
            ],

            // Persetujuan
            // 'agree_terms' => 'required|accepted',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Nama wajib diisi.',
            'username.required' => 'Username wajib diisi.',
            'username.unique' => 'Username sudah digunakan.',
            'username.regex' => 'Username gak boleh pakai spasi.',
            'phone.required' => 'No. Handphone wajib diisi.',
            'phone.unique' => 'No. Handphone sudah terdaftar.',
            'email.required' => 'Email wajib diisi.',
            'email.email' => 'Email wajib diisi.',
            'email.unique' => 'Email sudah terdaftar.',
            'password.required' => 'Password wajib diisi.',
            'password.password.min' => 'Password minimal 6 karakter.',
            'password.password.letters' => 'Password harus mengandung huruf.',
            'password.password.numbers' => 'Password harus mengandung angka.',
            'password.confirmed' => 'Harap konfirmasi ulang password.',
        ];
    }
}