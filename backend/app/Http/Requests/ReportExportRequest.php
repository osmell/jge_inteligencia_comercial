<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChoosesProductMix;
use App\Services\Reports\Output\ReportFiles;
use Illuminate\Validation\Rule;

/** Descarga de un reporte: el mismo periodo y filtros de la pantalla más el formato (y, en la mezcla de productos, a quién se evalúa y el nivel). */
class ReportExportRequest extends ReportDetailRequest
{
    use ChoosesProductMix;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + $this->productMixRules() + ['format' => ['required', Rule::in(ReportFiles::FORMATS)]];
    }

    public function fileFormat(): string
    {
        return (string) $this->validated('format');
    }
}
