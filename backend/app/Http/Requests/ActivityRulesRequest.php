<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Días para considerar inactivo y perdido a un cliente. */
class ActivityRulesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'inactive_days' => ['required', 'integer', 'min:1', 'max:365'],
            'lost_days' => ['required', 'integer', 'gt:inactive_days', 'max:1095'],
        ];
    }

    public function messages(): array
    {
        return ['lost_days.gt' => 'Los días para considerar perdido deben ser mayores que los de inactividad.'];
    }
}
