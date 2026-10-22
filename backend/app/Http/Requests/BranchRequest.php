<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Branch;
use App\Services\DataScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BranchRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        $branch = $this->route('branch');
        $companyId = $this->input('company_id', $branch instanceof Branch ? $branch->company_id : null);

        return [
            // Al editar, la compañía es opcional; cambiarla se permite solo si la sucursal no tiene datos (lo comprueba el controlador).
            'company_id' => [$branch ? 'sometimes' : 'required', 'integer', Rule::exists('companies', 'id'), $this->companyInScope(...)],
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
