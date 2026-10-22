<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Operator;
use App\Models\Seller;

/** Texto legible de los filtros aplicados a un reporte (compañía, sucursal, vendedor…). */
class FilterDescriber
{
    /** @param  array<string, mixed>  $filters */
    public function describe(array $filters): string
    {
        $names = [
            'company_id' => ['Compañía', fn ($id) => Company::find((int) $id)?->name],
            'branch_id' => ['Sucursal', fn ($id) => Branch::find((int) $id)?->name],
            'seller_id' => ['Vendedor', fn ($id) => Seller::find((int) $id)?->name],
            'operator_id' => ['Operador', fn ($id) => Operator::find((int) $id)?->name],
            'group' => ['Grupo', null], 'line' => ['Línea', null], 'category' => ['Categoría', null], 'product_code' => ['Producto', null], 'customer_code' => ['Cliente', null],
        ];

        $parts = [];
        foreach ($names as $key => [$label, $resolve]) {
            if (isset($filters[$key]) && $filters[$key] !== '') {
                $parts[] = "{$label}: ".($resolve ? ($resolve($filters[$key]) ?? $filters[$key]) : $filters[$key]);
            }
        }

        return $parts ? implode('; ', $parts) : 'Ninguno';
    }
}
