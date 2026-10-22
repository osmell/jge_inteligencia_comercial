<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Branch;
use App\Models\Role;
use App\Services\UserAccess;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UserRequest extends FormRequest
{
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('username'))) {
            $this->merge(['username' => mb_strtolower(trim($this->input('username')))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $user = $this->route('user');

        return [
            // Nombre de usuario, no correo: sin '@' ni espacios.
            'username' => ['required', 'string', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($user)],
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'password' => [$user ? 'nullable' : 'required', Password::min(10)->letters()->numbers()],
            'role_id' => ['required', 'integer', Rule::exists('roles', 'id')],
            'is_active' => ['sometimes', 'boolean'],
            'company_ids' => ['present', 'array'],
            'company_ids.*' => ['integer', 'distinct', Rule::exists('companies', 'id')],
            'branch_ids' => ['present', 'array'],
            'branch_ids.*' => ['integer', 'distinct', Rule::exists('branches', 'id')],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function ($validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            // Una sucursal solo tiene sentido si su compañía también está asignada.
            $companyIds = collect((array) $this->input('company_ids'));
            $orphans = Branch::whereIn('id', $this->input('branch_ids'))
                ->whereNotIn('company_id', $companyIds)->exists();

            if ($orphans) {
                $validator->errors()->add('branch_ids', 'Cada sucursal debe pertenecer a una compañía asignada al usuario.');
            }

            // Evita la escalada de privilegios de quien no es Administrador.
            $access = app(UserAccess::class);
            $actor = $this->user();
            $role = Role::find($this->input('role_id'));
            if ($role && ! $access->canAssignRole($actor, $role)) {
                $validator->errors()->add('role_id', 'No puedes asignar el rol Administrador.');
            }
            $assignable = $access->assignableCompanyIds($actor);
            if ($assignable !== null && collect((array) $this->input('company_ids'))->diff($assignable)->isNotEmpty()) {
                $validator->errors()->add('company_ids', 'Solo puedes asignar compañías sobre las que tienes acceso completo.');
            }

            // Evita que el administrador se bloquee a sí mismo.
            $user = $this->route('user');
            if ($user && $user->is($this->user())) {
                if ((int) $this->input('role_id') !== $user->role_id) {
                    $validator->errors()->add('role_id', 'No puedes cambiar tu propio rol.');
                }
                if ($this->has('is_active') && ! $this->boolean('is_active')) {
                    $validator->errors()->add('is_active', 'No puedes desactivar tu propia cuenta.');
                }
            }
        }];
    }
}
