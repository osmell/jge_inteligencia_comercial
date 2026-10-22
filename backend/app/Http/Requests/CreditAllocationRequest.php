<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Base de la captura de abonos y notas de crédito: cliente, fecha y reparto entre facturas. */
abstract class CreditAllocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    protected function baseRules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:255'],
            'occurred_on' => ['required', 'date_format:Y-m-d'],
            'allocations' => ['required', 'array', 'min:1', 'max:200'],
            'allocations.*.invoice_id' => ['required', 'integer', 'min:1'],
            'allocations.*.amount' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
        ];
    }

    /** @return list<array{invoice_id: int, amount: string}> */
    public function allocations(): array
    {
        return array_map(fn (array $a) => ['invoice_id' => (int) $a['invoice_id'], 'amount' => (string) $a['amount']], array_values((array) $this->input('allocations')));
    }

    public function messages(): array
    {
        return ['allocations.*.amount.regex' => 'El importe debe ser un número positivo con hasta 4 decimales.'];
    }
}
