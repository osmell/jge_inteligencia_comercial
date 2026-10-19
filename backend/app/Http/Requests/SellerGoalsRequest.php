<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Mes consultado (GET) o metas a guardar (PUT). */
class SellerGoalsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rules = [
            'month' => ['required', 'date_format:Y-m'],
            'company_id' => ['nullable', 'integer', 'exists:companies,id'],
        ];

        if ($this->isMethod('PUT')) {
            return $rules + [
                'goals' => ['required', 'array', 'min:1', 'max:500'],
                'goals.*.seller_id' => ['required', 'integer', 'distinct', 'exists:sellers,id'],
                'goals.*.amount' => ['nullable', 'numeric', 'min:0', 'max:999999999999'],
                'goals.*.days_off' => ['nullable', 'integer', 'min:0', 'max:31'],
                'goals.*.source' => ['nullable', Rule::in(['manual', 'suggested'])],
                'goals.*.starts_on' => ['nullable', 'date'],
                'goals.*.ends_on' => ['nullable', 'date', 'after_or_equal:goals.*.starts_on'],
            ];
        }

        if (str_ends_with($this->path(), 'goals/suggestions')) {
            return $rules + [
                'months' => ['nullable', 'integer', 'min:1', 'max:12'],
                'growth_pct' => ['nullable', 'numeric', 'min:-90', 'max:500'],
            ];
        }

        if (str_ends_with($this->path(), 'goals/export')) {
            return $rules + ['format' => ['nullable', Rule::in(['csv', 'pdf'])]];
        }

        return $rules;
    }
}
