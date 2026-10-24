<?php

declare(strict_types=1);

namespace App\Services\Reports\Output\Documents;

use App\Services\Reports\Output\ReportColumn as C;
use App\Services\Reports\Output\ReportDocument;
use App\Services\Reports\Output\ReportNotes;
use App\Services\Reports\Output\ReportSection;

/** Reporte de sucursales como documento exportable. */
final class BranchDocument
{
    /**
     * @param  array<string, mixed>  $r  la respuesta de BranchReport::build
     * @param  array<string, mixed>|null  $mix  la respuesta de BranchCategoryMix::build (mezcla de categorías)
     */
    public function build(array $r, string $filters, ?array $mix = null): ReportDocument
    {
        $t = $r['totals'];
        $lv = fn (mixed $value, string $level): array => ['value' => $value, 'level' => $level];

        $rows = [];
        foreach ($r['rows'] as $row) {
            $s = $row['sales'];
            $d = $row['dependency'];
            $i = $row['inventory'];
            $sem = $row['semaphores'];
            $rows[] = [
                $row['rank'], $row['branch']['name'], $row['branch']['company'],
                $s['net_total'], $lv($s['growth_pct'], $sem['growth_pct']), $s['documents'], $s['documents_growth_pct'], $s['average_ticket'], $s['customers'],
                $lv($s['discount_pct'], $sem['discount_pct']), $lv($s['below_list_pct'], $sem['below_list_pct']),
                $d['top_seller'] ?? 'sin vendedores', $lv($d['top_seller_pct'], $sem['top_seller_dependency_pct']), $lv($s['top3_concentration_pct'], $sem['top3_concentration_pct']),
                $row['portfolio']['total'], $lv($row['portfolio']['lost_pct'], $sem['lost_customers_pct']),
                $row['credit']['balance'], $lv($row['credit']['overdue_pct'], $sem['overdue_pct']),
                $i['products_in_stock'], $lv($i['no_rotation_pct'], $sem['no_rotation_pct']), $lv($i['stockout_demand_pct'], $sem['stockout_demand_pct']), $lv($i['open_shortfalls'], $sem['open_shortfalls']),
            ];
        }

        $columns = [
            new C('#', C::INT), new C('Sucursal'), new C('Compañía'),
            new C('Venta neta', C::MONEY), new C('Crecimiento', C::SIGNED_PCT, true), new C('Documentos', C::INT), new C('Crecimiento de documentos', C::SIGNED_PCT), new C('Ticket', C::MONEY), new C('Clientes', C::INT),
            new C('Descuento', C::PCT, true), new C('Bajo precio normal', C::PCT, true),
            new C('Vendedor principal'), new C('Dependencia del vendedor principal', C::PCT, true), new C('Top 3 clientes', C::PCT, true),
            new C('Clientes en cartera', C::INT), new C('Perdidos', C::PCT, true),
            new C('Por cobrar', C::MONEY), new C('Vencido', C::PCT, true),
            new C('Productos con existencia', C::INT), new C('Sin rotación', C::PCT, true), new C('Agotados con demanda', C::PCT, true), new C('Faltantes abiertos', C::INT, true),
        ];

        $sections = [new ReportSection('Evaluación de sucursales', $columns, $rows, '«Vendedor principal»: el que más vende de la sucursal; no cuenta a los vendedores «Tienda …» de mostrador. Las existencias se cuentan en productos, sin costos.')];
        if ($mix !== null && $mix['categories'] !== []) {
            $mixColumns = [new C('Categoría'), new C('% del total', C::PCT)];
            foreach ($mix['branches'] as $b) {
                $mixColumns[] = new C($b['name'].' (% de su venta)', C::PCT);
                $mixColumns[] = new C($b['name'].' (índice)', C::INT);
            }
            $mixRows = [];
            foreach ($mix['categories'] as $cat) {
                $row = [$cat['name'], $cat['share_pct']];
                foreach ($cat['cells'] as $cell) {
                    $row[] = $cell['share_pct'];
                    $row[] = $cell['index'];
                }
                $mixRows[] = $row;
            }
            $sections[] = new ReportSection('Mezcla de categorías por sucursal', $mixColumns, $mixRows, "Índice 100 = la sucursal vende esa categoría en la misma proporción que el conjunto; 200 = el doble; 50 = la mitad. Se muestran las {$mix['shown']} categorías con más venta de {$mix['total_categories']}.", anchors: 1);
        }

        return new ReportDocument(
            'Reporte de sucursales', 'reporte-sucursales', Header::lines($r, $filters), ReportNotes::for($r['notes']),
            [
                ['label' => 'Venta neta', 'value' => $t['net_total'], 'kind' => C::MONEY],
                ['label' => 'Crecimiento contra la comparación', 'value' => $t['growth_pct'], 'kind' => C::SIGNED_PCT],
                ['label' => 'Documentos', 'value' => $t['documents'], 'kind' => C::INT],
                ['label' => 'Saldo por cobrar', 'value' => $t['credit_balance'], 'kind' => C::MONEY],
                ['label' => 'Saldo vencido', 'value' => $t['credit_overdue'], 'kind' => C::MONEY],
                ['label' => 'Productos sin rotación', 'value' => $t['no_rotation'], 'kind' => C::INT],
                ['label' => 'Agotados con demanda', 'value' => $t['stockout_with_demand'], 'kind' => C::INT],
                ['label' => 'Faltantes abiertos', 'value' => $t['open_shortfalls'], 'kind' => C::INT],
                ['label' => 'Indicadores críticos', 'value' => $t['red_flags'], 'kind' => C::INT],
                ['label' => 'Contraste: venta consolidada', 'value' => $r['reconciliation']['consolidated_net'], 'kind' => C::MONEY],
                ['label' => 'Contraste: suma de las sucursales', 'value' => $r['reconciliation']['rows_net'], 'kind' => C::MONEY],
                ['label' => 'Contraste: diferencia', 'value' => $r['reconciliation']['net_difference'], 'kind' => C::MONEY],
            ],
            $sections,
        );
    }
}
