<?php

declare(strict_types=1);

namespace Modules\ExtPurchaseRequest\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RejectPurchaseRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
