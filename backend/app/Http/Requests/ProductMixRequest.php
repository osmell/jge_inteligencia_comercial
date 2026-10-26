<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChoosesProductMix;

/** Periodo y filtros de los reportes de evaluación, más el nivel del catálogo y a quién se evalúa. */
class ProductMixRequest extends ReportPeriodRequest
{
    use ChoosesProductMix;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + $this->productMixRules();
    }
}
