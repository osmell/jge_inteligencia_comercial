<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Asigna (o quita, con null) el tipo de un cliente. */
class CustomerAssignTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:255'],
            'customer_type_id' => ['present', 'nullable', 'integer', Rule::exists('customer_types', 'id')->where('active', true)],
        ];
    }
}
