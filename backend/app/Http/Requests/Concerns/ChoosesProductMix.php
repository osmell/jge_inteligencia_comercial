<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Services\Reports\Products\ProductFigures;
use Illuminate\Validation\Rule;

/** A quién se evalúa (vendedor u operador) y por qué nivel del catálogo se ve la venta, en el reporte de mezcla de productos. */
trait ChoosesProductMix
{
    /** @return array<string, mixed> */
    protected function productMixRules(): array
    {
        return [
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
