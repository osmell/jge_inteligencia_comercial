<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta o edición de una cotización (el cliente no se cambia). */
class QuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'company_id' => [$create ? 'required' : 'prohibited', 'integer', 'min:1'],
            'code' => [$create ? 'required' : 'prohibited', 'string', 'max:255'],
            'status' => [$create ? 'nullable' : 'prohibited', Rule::in(['draft', 'issued'])],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:9999999999999.99', 'decimal:0,2'],
            'valid_until' => ['nullable', 'date', 'after:2000-01-01', 'before:2100-01-01'],
            'description' => ['nullable', 'string', 'max:2000'],
            'external_reference' => ['nullable', 'string', 'max:100'],
        ];
    }

    public function messages(): array
    {
        return ['amount.decimal' => 'El importe admite hasta 2 decimales.'];
    }
}
