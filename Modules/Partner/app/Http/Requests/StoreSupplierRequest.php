<?php

namespace Modules\Partner\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreSupplierRequest extends FormRequest
{
    protected string $permissionPrefix = 'supplier';

    protected function prepareForValidation(): void
    {
        $this->normalize([
            'code'  => fn(string $v) => trim($v) === '' ? null : strtoupper(trim($v)),
            'phone' => fn(string $v) => trim($v) === '' ? null : preg_replace('/[\s\-().]/', '', $v),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => [
                'required',
                'string',
                'max:30',
                Rule::unique('suppliers', 'code'),
            ],
            'name'              => [...$this->presence(), 'string', 'max:150'],
            'contact_person'    => ['nullable', 'string', 'max:150'],
            'phone'             => ['nullable', 'string', 'regex:/^\+?[0-9]{6,20}$/'],
            'email'             => ['nullable', 'email:rfc', 'max:150'],
            'tax_id'            => ['nullable', 'string', 'max:30'],
            'address'           => ['nullable', 'string', 'max:1000'],
            'region_code'      => ['nullable', 'string', Rule::exists('wilayah', 'kode')],
            'postal_code'    => ['nullable', 'integer'],
            'payment_term_days'   => [...$this->presence(), 'integer', 'min:0', 'max:3650'],
            'bank_name'      => ['nullable', 'string', 'max:100'],
            'bank_account_name'   => ['nullable', 'string', 'max:150'],
            'bank_account_number' => ['nullable', 'integer'],
            'notes'          => ['nullable', 'string', 'max:2000'],
            'is_active'      => [...$this->presence(), 'boolean'],
        ];
    }

    public function attributes(): array
    {
        return [
            'code'                => 'kode',
            'name'                => 'nama',
            'contact_person'      => 'kontak person',
            'phone'               => 'telepon',
            'email'               => 'email',
            'tax_id'              => 'NPWP',
            'address'             => 'alamat',
            'region_code'         => 'wilayah',
            'postal_code'         => 'kode pos',
            'payment_term_days'   => 'termin pembayaran',
            'bank_name'           => 'nama bank',
            'bank_account_name'   => 'nama pemilik rekening',
            'bank_account_number' => 'nomor rekening',
            'notes'               => 'catatan',
            'is_active'           => 'status aktif',
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'    => 'Nama supplier wajib diisi.',
            'code.required'    => 'Kode supplier wajib diisi.',
            'code.unique'      => 'Kode supplier sudah digunakan.',
            'payment_term_days.required' => 'Payment term wajib diisi.',
            'phone.regex'      => 'Telepon hanya boleh berisi angka (6-20 digit), boleh diawali tanda +.',
            'region_code.exists' => 'Wilayah tidak ditemukan.',
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
