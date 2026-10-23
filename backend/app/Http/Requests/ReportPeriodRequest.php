<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Periodo, comparación y filtros de organización comunes a los reportes de evaluación. */
class ReportPeriodRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'compare' => ['nullable', Rule::in(ReportPeriod::MODES)],
            'compare_from' => ['nullable', 'required_if:compare,custom', 'date_format:Y-m-d'],
            'compare_to' => ['nullable', 'required_if:compare,custom', 'date_format:Y-m-d', 'after_or_equal:compare_from'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'branch_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    /** @return list<\Closure> */
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

    public function period(): ReportPeriod
    {
        $custom = fn (string $key): ?CarbonImmutable => is_string($this->validated($key)) ? CarbonImmutable::parse($this->validated($key)) : null;

        return ReportPeriod::resolve($this->from(), $this->to(), $this->validated('compare') ?? ReportPeriod::PREVIOUS, $custom('compare_from'), $custom('compare_to'));
    }

    public function companyId(): ?int
    {
        return $this->validated('company_id') === null ? null : (int) $this->validated('company_id');
    }

    /** Filtros de venta (la compañía se resuelve aparte, con el alcance de la persona). */
    /** @return array<string, mixed> */
    public function salesFilters(): array
    {
        return array_filter(['branch_id' => $this->validated('branch_id')], fn ($v) => $v !== null);
    }

    /** @return array<string, mixed> lo que queda en Auditoría: el periodo y los filtros pedidos */
    public function auditFilters(): array
    {
        return array_filter($this->validated(), fn ($v) => $v !== null);
    }
}
