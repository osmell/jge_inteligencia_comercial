<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Unidad base y equivalencias de un producto: cada equivalencia indica cuántas unidades base vale 1 de esa unidad. */
class ProductUnitsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'base_unit_id' => ['required', 'integer', 'exists:units,id'],
            'equivalences' => ['present', 'array', 'max:30'],
            'equivalences.*.unit_id' => ['required', 'integer', 'distinct', 'exists:units,id'],
            'equivalences.*.factor' => ['required', 'regex:/^\d{1,6}(\.\d{1,6})?$/'],
        ];
    }

    public function messages(): array
    {
        return ['equivalences.*.factor.regex' => 'El factor debe ser un número positivo con hasta 6 decimales.', 'equivalences.*.unit_id.distinct' => 'Una unidad solo puede aparecer una vez.'];
    }
}
