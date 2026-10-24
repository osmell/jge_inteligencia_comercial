<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Periodo y filtros de un reporte de evaluación; el detalle de facturación añade la página. */
class ReportDetailRequest extends ReportPeriodRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['page' => ['nullable', 'integer', 'min:1', 'max:100000']];
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
