<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', 'min:1'],
            'seller_id' => ['nullable', 'integer', 'min:1'],
            'mine' => ['nullable', 'boolean'],
            'horizon_days' => ['nullable', 'integer', 'min:0', 'max:90'],
            'no_contact_days' => ['nullable', 'integer', 'min:1', 'max:365'],
        ];
    }

    /** @return array{company_id: int|null, seller_id: int|null, mine: bool, horizon_days: int, no_contact_days: int} */
    public function options(): array
    {
        return [
            'company_id' => $this->integer('company_id') ?: null,
            'seller_id' => $this->integer('seller_id') ?: null,
            'mine' => $this->boolean('mine'),
            'horizon_days' => $this->has('horizon_days') ? $this->integer('horizon_days') : 7,
            'no_contact_days' => $this->integer('no_contact_days') ?: 30,
        ];
    }
}
