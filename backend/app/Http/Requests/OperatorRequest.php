<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Operator;
use App\Services\DataScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Alta o cambios de un facturador (operador): nombre y estado. */
class OperatorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        /** @var Operator|null $operator */
        $operator = $this->route('operator');
        $create = $operator === null;
        $companyId = $create ? $this->integer('company_id') : $operator->company_id;

        return [
            'company_id' => $create ? ['required', 'integer', Rule::exists('companies', 'id'), $this->companyInScope(...)] : ['prohibited'],
            'name' => [$create ? 'required' : 'sometimes', 'string', 'max:255', function (string $attribute, mixed $value, \Closure $fail) use ($companyId, $operator) {
                $taken = Operator::where('company_id', $companyId)->where('normalized_name', Operator::normalize((string) $value))
                    ->when($operator, fn ($q) => $q->where('id', '!=', $operator->id))->exists();
                if ($taken) {
                    $fail('Ya existe un facturador con ese nombre en la compañía.');
                }
            }],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    private function companyInScope(string $attribute, mixed $value, \Closure $fail): void
    {
        $allowed = app(DataScope::class)->fullCompanyIds($this->user());
        if ($allowed !== null && ! in_array((int) $value, $allowed, true)) {
            $fail('No tienes acceso completo a la compañía seleccionada.');
        }
    }
}
