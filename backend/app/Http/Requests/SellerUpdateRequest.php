<?php

namespace App\Http\Requests;

use App\Models\Role;
use App\Models\Seller;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Cambios del catálogo de vendedores: sucursal, «Tienda» por defecto y supervisor. */
class SellerUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        /** @var Seller $seller */
        $seller = $this->route('seller');

        return [
            'branch_id' => ['sometimes', 'nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $seller->company_id)],
            'is_default' => ['sometimes', 'boolean'],
            'supervisor_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')->where('is_active', true)
                ->whereIn('role_id', Role::where('slug', Role::SUPERVISOR_VENTAS)->pluck('id')->all())],
        ];
    }

    public function after(): array
    {
        return [function ($validator) {
            /** @var Seller $seller */
            $seller = $this->route('seller');
            $branch = $this->has('branch_id') ? $this->input('branch_id') : $seller->branch_id;
            $default = $this->has('is_default') ? $this->boolean('is_default') : $seller->is_default;
            if ($default && ! $branch) {
                $validator->errors()->add('is_default', 'Para ser el vendedor por defecto debe tener una sucursal.');
            }
        }];
    }
}
