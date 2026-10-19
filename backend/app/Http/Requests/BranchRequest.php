<?php

namespace App\Http\Requests;

use App\Models\Branch;
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
            'company_id' => $branch ? ['prohibited'] : ['required', 'integer', Rule::exists('companies', 'id')],
            'code' => ['required', 'string', 'max:20', Rule::unique('branches', 'code')->where('company_id', $companyId)->ignore($branch)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
