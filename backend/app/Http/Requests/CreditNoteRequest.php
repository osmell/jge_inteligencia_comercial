<?php

declare(strict_types=1);

namespace App\Http\Requests;

class CreditNoteRequest extends CreditAllocationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $money = ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'];

        return $this->baseRules() + [
            'series' => ['required', 'string', 'max:50'],
            'number' => ['required', 'string', 'max:50'],
            'subtotal' => $money,
            'tax_total' => $money,
            'total' => $money,
            'observations' => ['nullable', 'string', 'max:500'],
        ];
    }
}
