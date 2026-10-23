<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Operator;
use App\Models\Seller;

/**
 * Lo que la persona puede elegir en los filtros de los reportes: sus compañías y sucursales y, de cada compañía suya, TODOS los vendedores
 * y operadores (supervisión evalúa a todo el equipo de la compañía, aunque solo trabaje con algunas sucursales).
 */
class ReportContext
{
    /** @return array<string, mixed> */
    public function for(): array
    {
        // Compañías y sucursales ya vienen limitadas a su ámbito por el global scope.
        $companies = Company::query()->orderBy('name')->get(['id', 'code', 'name']);
        $ids = $companies->pluck('id')->all();

        return [
            'companies' => $companies->all(),
            'branches' => Branch::query()->orderBy('name')->get(['id', 'company_id', 'code', 'name'])->all(),
            'sellers' => Seller::query()->whereIn('company_id', $ids)->orderBy('name')->get(['id', 'company_id', 'branch_id', 'name', 'is_active'])->all(),
            'operators' => Operator::query()->whereIn('company_id', $ids)->orderBy('name')->get(['id', 'company_id', 'name', 'is_active'])->all(),
            'comparison_modes' => ReportPeriod::MODES,
            'thresholds' => config('reports.thresholds'),
        ];
    }
}
