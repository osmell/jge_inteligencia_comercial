<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Quotation;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuotationsListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['nullable', 'integer', 'min:1', 'required_with:code'],
            'code' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', Rule::in([...Quotation::STATUSES, Quotation::EXPIRED])],
            'mine' => ['nullable', 'boolean'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function page(): int
    {
        return max(1, $this->integer('page'));
    }
}
