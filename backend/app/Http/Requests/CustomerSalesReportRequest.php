<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Periodo y filtros del reporte de ventas por cliente. */
class CustomerSalesReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'format' => [str_ends_with($this->path(), 'export') ? 'required' : 'nullable', Rule::in(['csv', 'xlsx'])],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            if (! $validator->errors()->hasAny(['from', 'to']) && $this->from()->diffInDays($this->to()) + 1 > SalesStatsRequest::MAX_DAYS) {
                $validator->errors()->add('to', 'El rango máximo es de '.SalesStatsRequest::MAX_DAYS.' días.');
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

    public function companyId(): ?int
    {
        return $this->integer('company_id') ?: null;
    }

    public function branchId(): ?int
    {
        return $this->integer('branch_id') ?: null;
    }

    public function page(): int
    {
        return max(1, $this->integer('page'));
    }
}
