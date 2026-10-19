<?php

namespace App\Http\Requests;

use App\Services\SalesStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesStatsRequest extends FormRequest
{
    /** Máximo de días por consulta (3 años): acota el costo de las agregaciones. */
    public const MAX_DAYS = 1096;

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'seller_id' => ['nullable', 'integer', 'min:1'],
            'operator_id' => ['nullable', 'integer', 'min:1'],
            'category' => ['nullable', 'string', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:255'],
            'customer_code' => ['nullable', 'string', 'max:255'],
            'dimension' => [str_ends_with($this->path(), 'breakdown') ? 'required' : 'nullable', Rule::in(SalesStatistics::dimensions())],
            'sort' => ['nullable', Rule::in(SalesStatistics::SORTS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            if ($this->from()->diffInDays($this->to()) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('to', 'El rango máximo es de '.self::MAX_DAYS.' días.');
            }
        }];
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('from'))->startOfDay();
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->validated('to'))->startOfDay();
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['company_id', 'branch_id', 'seller_id', 'operator_id', 'category', 'product_code', 'customer_code']);
    }
}
