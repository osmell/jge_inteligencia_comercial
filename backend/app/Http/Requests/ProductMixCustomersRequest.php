<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChoosesProductMix;

/** Quiénes compraron un elemento (producto, categoría, línea o grupo) del reporte de mezcla de productos; opcionalmente solo a un vendedor u operador. */
class ProductMixCustomersRequest extends ReportPeriodRequest
{
    use ChoosesProductMix;

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return parent::rules() + $this->productMixRules() + [
            'key' => ['required', 'string', 'max:255'],
            'seller_id' => ['nullable', 'integer', 'min:1'],
            'operator_id' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function key(): string
    {
        return (string) $this->validated('key');
    }
}
