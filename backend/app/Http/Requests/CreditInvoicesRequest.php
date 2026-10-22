<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\CreditAging;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreditInvoicesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', 'min:1', 'required_with:code'],
            'code' => ['nullable', 'string', 'max:255'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['open', 'paid', 'all'])],
            'bucket' => ['nullable', Rule::in(CreditAging::buckets())],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function page(): int
    {
        return max(1, $this->integer('page'));
    }
}
