<?php

declare(strict_types=1);

namespace App\Services\Reports\Branches;

use App\Services\Sales\SalesBasis;
use App\Services\Sales\SalesQuery;
use Carbon\CarbonImmutable;

/**
 * Cuánto depende cada sucursal de una sola persona: porcentaje de su venta que aporta el vendedor que más vende. Los vendedores «Tienda …»
 * (los que el sistema crea por omisión para las ventas de mostrador) no son personas: no cuentan como dependencia, aunque su venta sí
 * forma parte del total de la sucursal.
 */
class BranchSellerFigures
{
    public function __construct(private readonly SalesQuery $query) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<int, array{sellers_count: int, top_seller: string|null, top_seller_pct: float|null}> sucursal → dependencia
     */
    public function forPeriod(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $rows = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->join('sellers as dep_s', 'dep_s.id', '=', 'sd.seller_id')
            ->selectRaw('sales_lines.branch_id as branch_id, sd.seller_id as seller_id, max(dep_s.name) as name, max(case when dep_s.is_default then 1 else 0 end) as is_default, sum('.SalesBasis::net('sales_lines').') as net')
            ->groupBy('sales_lines.branch_id', 'sd.seller_id')
            ->toBase()->get()->groupBy('branch_id');

        $out = [];
        foreach ($rows as $branchId => $sellers) {
            $total = (float) $sellers->sum('net');
            $people = $sellers->filter(fn ($s) => ! (int) $s->is_default)->sortByDesc(fn ($s) => (float) $s->net)->values();
            $top = $people->first();
            $out[(int) $branchId] = [
                'sellers_count' => $people->count(),
                'top_seller' => $top?->name,
                'top_seller_pct' => $top !== null && $total > 0 ? round((float) $top->net / $total * 100, 2) : null,
            ];
        }

        return $out;
    }
}
