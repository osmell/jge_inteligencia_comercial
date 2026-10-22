<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\SalesStatistics;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SalesStatsRequest extends FormRequest
{
    /** Máximo de días por consulta (3 años): acota el costo de las agregaciones. */
    public const MAX_DAYS = 1096;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            // Periodo de comparación opcional; sin él se usa el equivalente anterior.
            'compare_from' => ['nullable', 'required_with:compare_to', 'date_format:Y-m-d'],
            'compare_to' => ['nullable', 'required_with:compare_from', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'seller_id' => ['nullable', 'integer', 'min:1'],
            'operator_id' => ['nullable', 'integer', 'min:1'],
            'group' => ['nullable', 'string', 'max:255'],
            'line' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'product_code' => ['nullable', 'string', 'max:255'],
            'customer_code' => ['nullable', 'string', 'max:255'],
            'dimension' => [str_ends_with($this->path(), 'breakdown') ? 'required' : 'nullable', Rule::in(SalesStatistics::dimensions())],
            'sort' => ['nullable', Rule::in(SalesStatistics::SORTS)],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->hasAny(['from', 'to'])) {
                return;
            }

            if ($this->from()->diffInDays($this->to()) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('to', 'El rango máximo es de '.self::MAX_DAYS.' días.');
            }

            $compare = $this->compare();
            if ($compare && ! $validator->errors()->hasAny(['compare_from', 'compare_to']) && $compare[0]->diffInDays($compare[1]) + 1 > self::MAX_DAYS) {
                $validator->errors()->add('compare_to', 'El rango máximo es de '.self::MAX_DAYS.' días.');
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

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable}|null */
    public function compare(): ?array
    {
        $from = $this->input('compare_from');
        $to = $this->input('compare_to');

        if (! is_string($from) || ! is_string($to) || $from === '' || $to === '') {
            return null;
        }

        try {
            $start = CarbonImmutable::createFromFormat('!Y-m-d', $from);
            $end = CarbonImmutable::createFromFormat('!Y-m-d', $to);

            return $start === null || $end === null ? null : [$start, $end];
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    public function filters(): array
    {
        return $this->safe()->only(['company_id', 'branch_id', 'seller_id', 'operator_id', 'group', 'line', 'category', 'product_code', 'customer_code']);
    }
}
