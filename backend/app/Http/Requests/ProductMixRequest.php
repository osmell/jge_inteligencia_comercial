<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\Reports\Products\ProductFigures;
use Illuminate\Validation\Rule;

/** Periodo y filtros de los reportes de evaluación, más el nivel del catálogo y a quién se evalúa. */
class ProductMixRequest extends ReportPeriodRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + [
            'subject' => ['nullable', Rule::in(array_keys(ProductFigures::SUBJECTS))],
            'level' => ['nullable', Rule::in(ProductFigures::LEVELS)],
        ];
    }

    /** @return 'seller'|'operator' */
    public function subject(): string
    {
        return $this->validated('subject') === 'operator' ? 'operator' : 'seller';
    }

    public function level(): string
    {
        return (string) ($this->validated('level') ?? 'category');
    }
}
