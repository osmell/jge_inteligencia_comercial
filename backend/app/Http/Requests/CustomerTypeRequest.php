<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta o edición de un tipo de cliente. */
class CustomerTypeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $unique = Rule::unique('customer_types', 'name')->ignore($this->route('customerType'));

        return [
            'name' => [$this->isMethod('POST') ? 'required' : 'sometimes', 'string', 'max:100', $unique],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
