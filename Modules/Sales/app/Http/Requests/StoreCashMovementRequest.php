<?php

declare(strict_types=1);

namespace Modules\Sales\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Modules\Sales\Enums\CashMovementType;

class StoreCashMovementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(array_column(CashMovementType::cases(), 'value'))],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999999'],
            'reason' => ['required', 'string', 'max:255'],
        ];
    }
}
