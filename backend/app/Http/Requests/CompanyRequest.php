<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CompanyRequest extends FormRequest
{
    public function rules(): array
    {
        $company = $this->route('company');

        return [
            'code' => ['required', 'string', 'max:20', Rule::unique('companies', 'code')->ignore($company)],
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }
}
