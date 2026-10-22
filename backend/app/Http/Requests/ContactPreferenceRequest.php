<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** El cliente pidió (o retiró la petición de) no recibir comunicaciones. */
class ContactPreferenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:255'],
            'do_not_contact' => ['required', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500', 'required_if_accepted:do_not_contact'],
        ];
    }

    public function messages(): array
    {
        return ['reason.required_if_accepted' => 'Indica el motivo o cómo lo pidió el cliente.'];
    }
}
