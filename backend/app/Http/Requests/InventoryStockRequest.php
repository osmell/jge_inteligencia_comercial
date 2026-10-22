<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros de existencias (listado) y kárdex (un producto en una sucursal). */
class InventoryStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $kardex = $this->routeIs('inventory.kardex') || str_ends_with($this->path(), 'kardex');

        return [
            'company_id' => [$kardex ? 'required' : 'nullable', 'integer', 'min:1'],
            'branch_id' => [$kardex ? 'required' : 'nullable', 'integer', 'min:1'],
            'product_id' => [$kardex ? 'required' : 'prohibited', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['with', 'zero', 'all'])],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ];
    }

    public function page(): int
    {
        return max(1, $this->integer('page'));
    }

    public function day(string $field): ?CarbonImmutable
    {
        return $this->filled($field) ? CarbonImmutable::parse($this->string($field)->toString()) : null;
    }
}
