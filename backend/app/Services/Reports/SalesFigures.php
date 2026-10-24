<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Sales\SalesMetrics;
use App\Services\Sales\SalesQuery;
use Carbon\CarbonImmutable;

/**
 * Cifras de venta de cada vendedor, operador o sucursal en un periodo. Todas son sobre venta NETA (sin IVA).
 *
 *  - ventas bajo precio normal: parte de la venta (de las líneas que tienen precio normal) cuyo precio unitario quedó por debajo de ese precio;
 *  - descuento: el que trae el archivo, sobre las líneas que informan subtotal;
 *  - categorías por cliente: pares cliente-categoría distintos entre clientes distintos (más alto = vende más variedad a cada cliente);
 *  - concentración: porcentaje de su venta que sale de sus 3 mejores clientes.
 */
class SalesFigures
{
    /** Columna por la que se agrupa cada tipo de evaluado (lista blanca: nunca llega texto del usuario al SQL). */
    public const GROUP_COLUMNS = ['seller' => 'sd.seller_id', 'operator' => 'sd.operator_id', 'branch' => 'sales_lines.branch_id'];

    public function __construct(private readonly SalesQuery $query, private readonly SalesMetrics $metrics) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @param  'seller'|'operator'|'branch'  $dimension
     * @return array<int, array<string, mixed>> id del evaluado → cifras
     */
    public function forPeriod(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $dimension = 'seller'): array
    {
        $column = self::GROUP_COLUMNS[$dimension];
        $rows = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->selectRaw("{$column} as subject_id")
            ->selectRaw($this->query->aggregates())
            ->selectRaw('coalesce(sum(case when sales_lines.normal_price > 0 and sales_lines.unit_price < sales_lines.normal_price - 0.005 then sales_lines.net_total end), 0) as below_list_net')
            ->selectRaw('coalesce(sum(case when sales_lines.normal_price > 0 then sales_lines.net_total end), 0) as listed_net')
            ->selectRaw("count(distinct sd.customer_code || '|' || sales_lines.category_name) as customer_categories")
            ->groupByRaw($column)
            ->toBase()->get();

        $concentration = $this->concentration($companyIds, $from, $to, $filters, $column);

        $out = [];
        foreach ($rows as $r) {
            $f = $this->metrics->format($r);
            $net = (float) $r->net_total;
            $customers = (int) $r->customers;
            $out[(int) $r->subject_id] = [
                'net_total' => $net,
                'documents' => (int) $r->documents,
                'customers' => $customers,
                'average_ticket' => $f['average_ticket'] === null ? null : (float) $f['average_ticket'],
                'discount_pct' => $f['discount_pct'],
                'below_list_pct' => (float) $r->listed_net > 0 ? round((float) $r->below_list_net / (float) $r->listed_net * 100, 2) : null,
                'categories_per_customer' => $customers > 0 ? round((int) $r->customer_categories / $customers, 2) : null,
                'top3_concentration_pct' => $concentration[(int) $r->subject_id] ?? null,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<int, float|null> id del evaluado → % de su venta en sus 3 mejores clientes
     */
    private function concentration(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $column): array
    {
        $byCustomer = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->selectRaw("{$column} as subject_id, sd.customer_code as code, sum(sales_lines.net_total) as net")
            ->groupByRaw("{$column}, sd.customer_code")
            ->toBase()->get()->groupBy('subject_id');

        $out = [];
        foreach ($byCustomer as $subjectId => $customers) {
            $total = (float) $customers->sum('net');
            $top = (float) $customers->sortByDesc(fn ($c) => (float) $c->net)->take(3)->sum('net');
            $out[(int) $subjectId] = $total > 0 ? round($top / $total * 100, 2) : null;
        }

        return $out;
    }
}
