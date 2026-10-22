<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\CustomerInteraction;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta o corrección de un contacto con un cliente. */
class CustomerInteractionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $create = $this->isMethod('POST');

        return [
            'company_id' => [$create ? 'required' : 'prohibited', 'integer', 'min:1'],
            'code' => [$create ? 'required' : 'prohibited', 'string', 'max:255'],
            'channel' => ['required', Rule::in(CustomerInteraction::CHANNELS)],
            'direction' => ['required', Rule::in(CustomerInteraction::DIRECTIONS)],
            'outcome' => ['required', Rule::in(CustomerInteraction::OUTCOMES)],
            'occurred_at' => ['required', 'date', 'before_or_equal:now', 'after:2000-01-01'],
            'response' => ['nullable', 'string', 'max:2000'],
            'next_action' => ['nullable', 'string', 'max:500', 'required_with:next_action_at'],
            'next_action_at' => ['nullable', 'date', 'after_or_equal:occurred_at', 'before:2100-01-01'],
        ];
    }

    public function messages(): array
    {
        return [
            'occurred_at.before_or_equal' => 'La fecha del contacto no puede ser futura.',
            'next_action.required_with' => 'Describe la próxima acción.',
            'next_action_at.after_or_equal' => 'La próxima acción no puede ser anterior al contacto.',
        ];
    }
}
