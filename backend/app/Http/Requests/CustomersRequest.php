<?php

namespace App\Http\Requests;

use App\Services\CustomerInsights;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Búsqueda de clientes y consultas de una ficha (el código de cliente va en la consulta: puede traer caracteres especiales). */
class CustomersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $isSearch = str_ends_with($this->path(), 'v1/customers');

        return [
            'q' => ['nullable', 'string', 'max:100'],
            'code' => [$isSearch ? 'nullable' : 'required', 'string', 'max:255'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'sort' => ['nullable', Rule::in(CustomerInsights::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'document_id' => [str_ends_with($this->path(), 'document') ? 'required' : 'nullable', 'integer', 'min:1'],
        ];
    }

    public function companyId(): ?int
    {
        return $this->integer('company_id') ?: null;
    }

    public function page(): int
    {
        return max(1, $this->integer('page'));
    }
}
