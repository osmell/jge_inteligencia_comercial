<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Vendedor y facturador asignados a un cliente (null quita la asignación; sin vendedor aplica la «Tienda» de la sucursal). */
class CustomerAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $company = $this->integer('company_id');

        return [
            'company_id' => ['required', 'integer', 'min:1'],
            'code' => ['required', 'string', 'max:255'],
            'seller_id' => ['present', 'nullable', 'integer', Rule::exists('sellers', 'id')->where('company_id', $company)->where('is_active', true)],
            'operator_id' => ['present', 'nullable', 'integer', Rule::exists('operators', 'id')->where('company_id', $company)->where('is_active', true)],
        ];
    }
}
