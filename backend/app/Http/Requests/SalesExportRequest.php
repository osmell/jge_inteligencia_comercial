<?php

namespace App\Http\Requests;

use App\Services\SalesStatistics;
use Illuminate\Validation\Rule;

/** Mismos periodo y filtros que el dashboard; CSV exige la dimensión a exportar, Excel incluye todas y el PDF resume las principales. */
class SalesExportRequest extends SalesStatsRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'format' => ['required', Rule::in(['csv', 'xlsx', 'pdf'])],
            'dimension' => [Rule::requiredIf(fn () => $this->input('format') === 'csv'), 'nullable', Rule::in(SalesStatistics::dimensions())],
        ];
    }
}
