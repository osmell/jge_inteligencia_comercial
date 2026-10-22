<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Services\ProductCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Búsqueda en el catálogo de productos y sus niveles. */
class ProductsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'company_id' => ['nullable', 'integer', 'min:1'],
            'level1' => ['nullable', 'string', 'max:255'],
            'level2' => ['nullable', 'string', 'max:255'],
            'level3' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', Rule::in(ProductCatalog::SORTS)],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function page(): int
    {
        return max(1, $this->integer('page'));
    }
}
