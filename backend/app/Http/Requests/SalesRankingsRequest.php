<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/** Mismos periodo y filtros que el dashboard; el ranking es de productos o de categorías. */
class SalesRankingsRequest extends SalesStatsRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'dimension' => ['nullable', Rule::in(['product', 'category'])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
