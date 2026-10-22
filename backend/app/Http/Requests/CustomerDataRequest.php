<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Datos generales de un cliente (el código no se cambia: enlaza al cliente con sus ventas). */
class CustomerDataRequest extends FormRequest
{
    private const PHONE = '/^\+?[0-9][0-9 ()\-.]{3,24}$/';

    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'trade_name' => ['nullable', 'string', 'max:255'],
            'contact_name' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'regex:'.self::PHONE],
            'whatsapp' => ['nullable', 'string', 'regex:'.self::PHONE],
            'email' => ['nullable', 'string', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $this->integer('company_id'))],
        ];
    }

    public function messages(): array
    {
        return ['phone.regex' => 'No es un teléfono válido.', 'whatsapp.regex' => 'No es un número de WhatsApp válido.'];
    }
}
