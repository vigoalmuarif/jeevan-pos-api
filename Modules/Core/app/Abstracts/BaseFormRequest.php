<?php

namespace Modules\Core\Abstracts;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Dasar semua Form Request yang berada di dalam konteks merchant.
 *
 * - Satu class dipakai untuk create (POST) dan update (PUT/PATCH).
 * - Saat update, aturan `required` menjadi `sometimes|required`
 *   sehingga PATCH parsial tetap valid.
 */
abstract class BaseFormRequest extends FormRequest
{
    /** Prefix permission, mis. 'customer' => customer.create / customer.update. Kosong = tanpa cek. */
    protected string $permissionPrefix = '';

    public function authorize(): bool
    {
        if ($this->permissionPrefix === '') {
            return true;
        }

        $action = $this->isUpdate() ? 'update' : 'create';

        $user = $this->user('merchant');

        return $user !== null
            && ($user->hasRole('owner') || $user->can("{$this->permissionPrefix}.{$action}"));
    }

    /**
     * Rule `unique` / `exists` bawaan Laravel tidak memakai global scope Eloquent,
     * jadi scope merchant harus ditambahkan manual pada query rule tersebut.
     */
    protected function merchantId(): int
    {
        return (int) $this->user('merchant')->merchant_id;
    }

    protected function isUpdate(): bool
    {
        return in_array($this->method(), ['PUT', 'PATCH'], true);
    }

    /** @return list<string> */
    protected function presence(): array
    {
        return $this->isUpdate() ? ['sometimes', 'required'] : ['required'];
    }

    /** ID record dari route (model binding atau id mentah). */
    protected function routeId(string $param): int|string|null
    {
        $value = $this->route($param);

        return $value instanceof Model ? $value->getKey() : $value;
    }

    /**
     * Normalisasi hanya untuk key yang dikirim, agar PATCH parsial tidak
     * tanpa sengaja mengosongkan field yang tidak ikut dikirim.
     *
     * @param  array<string, callable(string): ?string>  $normalizers
     */
    protected function normalize(array $normalizers): void
    {
        $merge = [];

        foreach ($normalizers as $key => $callback) {
            $value = $this->input($key);

            if ($this->has($key) && is_string($value)) {
                $merge[$key] = $callback($value);
            }
        }

        $this->merge($merge);
    }
}