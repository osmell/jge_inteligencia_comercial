<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta o edición de una unidad de medida (el código no cambia una vez creada). */
class UnitRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'code' => $create ? ['required', 'string', 'max:30', 'regex:/^[a-z0-9_]+$/', Rule::unique('units', 'code')] : ['prohibited'],
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:60'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return ['code.regex' => 'El código solo admite minúsculas, números y guion bajo.', 'code.prohibited' => 'El código de una unidad no se puede cambiar.'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['code', 'name'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => trim($this->input($field))]);
            }
        }
    }
}
