<?php

namespace App\Http\Requests;

class CreditPaymentRequest extends CreditAllocationRequest
{
    public function rules(): array
    {
        return $this->baseRules() + [
            'method' => ['required', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
