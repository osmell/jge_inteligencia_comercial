<?php

declare(strict_types=1);

namespace App\Http\Requests;

class CreditPaymentRequest extends CreditAllocationRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return $this->baseRules() + [
            'method' => ['required', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
