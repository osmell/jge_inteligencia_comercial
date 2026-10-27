<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;

/**
 * Reasigna el costo de las líneas de componente a la línea principal de su factura (docs/30_CONCILIACION_FACTURACION.md).
 *
 * El catalizador, el reductor y el thinner se facturan con un precio simbólico (menor a 1: 0.0087, 0.0090, 0.0093, 0.0100) y venta casi
 * nula, pero tienen costo real; sin reasignarlo, el margen del producto que se pintó queda inflado y el del componente, negativo.
 * Línea de componente = unidades > 0, precio unitario entre 0 y 1 (sin incluir 1) y costo > 0. Línea principal = la de mayor neto de la misma
 * factura que no sea de componente (a igualdad, la primera); una factura sin línea principal deja el costo donde está.
 *
 * No modifica sales_lines: escribe en sales_line_component_costs. Es idempotente.
 */
final class ComponentCosts
{
    public const RULE_VERSION = 1;

    /** @return int componentes asignados en esta ejecución */
    public function run(?int $companyId = null): int
    {
        $main = DB::table('sales_lines as m')
            ->leftJoin('sales_line_corrections as mc', 'mc.sales_line_id', '=', 'm.id')
            ->whereRaw('not (m.units > 0 and m.unit_price > 0 and m.unit_price < 1)')
            ->when($companyId, fn ($q) => $q->where('m.company_id', $companyId))
            ->selectRaw('m.id, m.sales_document_id, row_number() over (partition by m.sales_document_id order by coalesce(mc.corrected_net, m.net_total) desc, m.line_number asc) as rn');

        $query = DB::table('sales_lines as c')
            ->joinSub($main, 'main', fn ($j) => $j->on('main.sales_document_id', '=', 'c.sales_document_id')->where('main.rn', '=', 1))
            ->where('c.units', '>', 0)->where('c.unit_price', '>', 0)->where('c.unit_price', '<', 1)->where('c.line_cost_total', '>', 0)
            ->when($companyId, fn ($q) => $q->where('c.company_id', $companyId))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales_line_component_costs as x')->whereColumn('x.component_line_id', 'c.id'))
            ->select(['c.id', 'main.id as main_id', 'c.company_id', 'c.branch_id', 'c.issued_on', 'c.line_cost_total', DB::raw(self::RULE_VERSION), DB::raw('current_timestamp'), DB::raw('current_timestamp')]);

        $assigned = DB::table('sales_line_component_costs')->insertUsing(
            ['component_line_id', 'main_line_id', 'company_id', 'branch_id', 'issued_on', 'cost', 'rule_version', 'created_at', 'updated_at'],
            $query,
        );

        $this->materialize();

        return $assigned;
    }

    /** Deja en sales_line_effective_costs el costo vigente de cada línea tocada: 0 en el componente y la suma en la línea principal. */
    private function materialize(): void
    {
        DB::table('sales_line_effective_costs')->insertUsing(
            ['sales_line_id', 'cost'],
            DB::table('sales_line_component_costs as k')->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales_line_effective_costs as e')->whereColumn('e.sales_line_id', 'k.component_line_id'))
                ->selectRaw('k.component_line_id, 0'),
        );

        DB::table('sales_line_effective_costs')->insertUsing(
            ['sales_line_id', 'cost'],
            DB::table('sales_line_component_costs as k')->join('sales_lines as l', 'l.id', '=', 'k.main_line_id')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales_line_effective_costs as e')->whereColumn('e.sales_line_id', 'k.main_line_id'))
                ->groupBy('k.main_line_id', 'l.line_cost_total')
                ->selectRaw('k.main_line_id, l.line_cost_total + sum(k.cost)'),
        );
    }
}
