<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Reports\Output\ReportFiles;
use Illuminate\Validation\Rule;

/** Descarga de un reporte: el mismo periodo y filtros de la pantalla más el formato. */
class ReportExportRequest extends ReportDetailRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + ['format' => ['required', Rule::in(ReportFiles::FORMATS)]];
    }

    public function fileFormat(): string
    {
        return (string) $this->validated('format');
    }
}
