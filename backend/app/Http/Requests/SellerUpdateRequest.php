<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Services\DataScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta o cambios del catálogo de vendedores: nombre, estado, sucursal, «Tienda» por defecto, supervisor y usuario vinculado. */
class SellerUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Seller|null $seller */
        $seller = $this->route('seller');
        $create = $seller === null;
        $companyId = $create ? $this->integer('company_id') : $seller->company_id;

        return [
            'company_id' => $create ? ['required', 'integer', Rule::exists('companies', 'id'), $this->companyInScope(...)] : ['prohibited'],
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:255', $this->uniqueName($companyId, $seller)],
            'is_active' => ['sometimes', 'boolean'],
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'is_default' => ['sometimes', 'boolean'],
            'supervisor_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)
                ->whereIn('role_id', Role::where('slug', Role::SUPERVISOR_VENTAS)->pluck('id')->all())],
            'user_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)
                ->whereIn('role_id', Role::where('slug', Role::VENDEDOR)->pluck('id')->all())],
        ];
    }

    /** @return list<\Closure> */
    public function after(): array
    {
        return [function ($validator) {
            /** @var Seller|null $seller */
            $seller = $this->route('seller');
            $branch = $this->has('branch_id') ? $this->input('branch_id') : $seller?->branch_id;
            $default = $this->has('is_default') ? $this->boolean('is_default') : ($seller !== null && $seller->is_default);
            if ($default && ! $branch) {
                $validator->errors()->add('is_default', 'Para ser el vendedor por defecto debe tener una sucursal.');
            }
        }];
    }

    /** El nombre se compara normalizado (sin mayúsculas ni espacios de más): así se enlazan los vendedores al importar ventas. */
    private function uniqueName(int $companyId, ?Seller $seller): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) use ($companyId, $seller) {
            $taken = Seller::where('company_id', $companyId)->where('normalized_name', Operator::normalize((string) $value))
                ->when($seller, fn ($q) => $q->where('id', '!=', $seller?->id))->exists();
            if ($taken) {
                $fail('Ya existe un vendedor con ese nombre en la compañía.');
            }
        };
    }

    private function companyInScope(string $attribute, mixed $value, \Closure $fail): void
    {
        $allowed = app(DataScope::class)->fullCompanyIds($this->user());
        if ($allowed !== null && ! in_array((int) $value, $allowed, true)) {
            $fail('No tienes acceso completo a la compañía seleccionada.');
        }
    }
}
