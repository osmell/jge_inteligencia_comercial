<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** Reporte de vendedores; el detalle de facturación de un vendedor añade la página. */
class SellerReportRequest extends ReportPeriodRequest
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
