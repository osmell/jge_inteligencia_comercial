<?php

namespace App\Http\Requests;

use App\Models\Branch;
use App\Services\DataScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    public function rules(): array
    {
        $branch = $this->route('branch');
        $companyId = $branch instanceof Branch ? $branch->company_id : $this->input('company_id');

        return [
            // La compañía no se puede cambiar una vez creada la sucursal.
            'company_id' => $branch ? ['prohibited'] : ['required', 'integer', Rule::exists('companies', 'id'), $this->companyInScope(...)],
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')->where('company_id', $companyId)->ignore($branch)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /** Quien no es Administrador solo crea sucursales en compañías sobre las que tiene acceso completo. */
    private function companyInScope(string $attribute, mixed $value, \Closure $fail): void
    {
        $allowed = app(DataScope::class)->fullCompanyIds($this->user());

        if ($allowed !== null && ! in_array((int) $value, $allowed, true)) {
            $fail('No tienes acceso completo a la compañía seleccionada.');
        }
    }
}
