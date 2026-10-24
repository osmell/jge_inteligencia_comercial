<?php

declare(strict_types=1);

namespace App\Services\Reports\Output\Documents;

use App\Services\Reports\Output\ReportColumn as C;
use App\Services\Reports\Output\ReportDocument;
use App\Services\Reports\Output\ReportNotes;
use App\Services\Reports\Output\ReportSection;

/** Reporte de operadores como documento exportable. */
final class OperatorDocument
{
    /**
     * @param  array<string, mixed>  $r  la respuesta de OperatorReport::build
     */
    public function build(array $r, string $filters): ReportDocument
    {
        $t = $r['totals'];
        $lv = fn (mixed $value, string $level): array => ['value' => $value, 'level' => $level];

        $rows = [];
        foreach ($r['rows'] as $row) {
            $s = $row['sales'];
            $p = $row['production'];
            $c = $row['control'];
            $sem = $row['semaphores'];
            $rows[] = [
                $row['rank'], $row['operator']['name'], $row['operator']['company'],
                $s['net_total'], $lv($s['growth_pct'], $sem['growth_pct']), $s['documents'], $s['documents_growth_pct'], $p['lines'], $p['lines_per_document'], $p['active_days'], $p['documents_per_active_day'], $s['average_ticket'],
                $lv($s['discount_pct'], $sem['discount_pct']), $lv($s['below_list_pct'], $sem['below_list_pct']),
                $c['high_discount_lines'], $lv($c['high_discount_lines_pct'], $sem['high_discount_lines_pct']),
                $c['zero_price_lines'], $lv($c['zero_price_lines_pct'], $sem['zero_price_lines_pct']),
                $c['atypical_documents'], $lv($c['atypical_documents_pct'], $sem['atypical_documents_pct']),
                $c['negative_documents'], $c['direct_sales_pct'],
            ];
        }

        $columns = [
            new C('#', C::INT), new C('Operador'), new C('Compañía'),
            new C('Venta neta', C::MONEY), new C('Crecimiento', C::SIGNED_PCT, true), new C('Documentos', C::INT), new C('Crecimiento de documentos', C::SIGNED_PCT), new C('Líneas', C::INT), new C('Líneas por documento', C::DECIMAL), new C('Días activos', C::INT), new C('Documentos por día activo', C::DECIMAL), new C('Ticket', C::MONEY),
            new C('Descuento', C::PCT, true), new C('Bajo precio normal', C::PCT, true),
            new C('Líneas con descuento alto', C::INT), new C('% con descuento alto', C::PCT, true),
            new C('Líneas sin precio', C::INT), new C('% sin precio', C::PCT, true),
            new C('Documentos atípicos', C::INT), new C('% atípicos', C::PCT, true),
            new C('Documentos negativos', C::INT), new C('Venta directa', C::PCT),
        ];

        return new ReportDocument(
            'Reporte de operadores', 'reporte-operadores', Header::lines($r, $filters), ReportNotes::for($r['notes']),
            [
                ['label' => 'Venta neta facturada', 'value' => $t['net_total'], 'kind' => C::MONEY],
                ['label' => 'Crecimiento contra la comparación', 'value' => $t['growth_pct'], 'kind' => C::SIGNED_PCT],
                ['label' => 'Documentos', 'value' => $t['documents'], 'kind' => C::INT],
                ['label' => 'Líneas', 'value' => $t['lines'], 'kind' => C::INT],
                ['label' => 'Líneas con descuento alto', 'value' => $t['high_discount_lines'], 'kind' => C::INT],
                ['label' => 'Líneas sin precio', 'value' => $t['zero_price_lines'], 'kind' => C::INT],
                ['label' => 'Documentos atípicos', 'value' => $t['atypical_documents'], 'kind' => C::INT],
                ['label' => 'Documentos negativos', 'value' => $t['negative_documents'], 'kind' => C::INT],
                ['label' => 'Indicadores críticos', 'value' => $t['red_flags'], 'kind' => C::INT],
                ['label' => 'Contraste: venta consolidada', 'value' => $r['reconciliation']['consolidated_net'], 'kind' => C::MONEY],
                ['label' => 'Contraste: suma de los operadores', 'value' => $r['reconciliation']['rows_net'], 'kind' => C::MONEY],
                ['label' => 'Contraste: diferencia', 'value' => $r['reconciliation']['net_difference'], 'kind' => C::MONEY],
            ],
            [new ReportSection('Evaluación de operadores', $columns, $rows, 'Descuento alto, sin precio y atípicos: definiciones en docs/29_REPORTES.md. Venta directa: el vendedor y el operador son la misma persona.')],
        );
    }
}
