<?php

declare(strict_types=1);

namespace App\Services\Reports\Output\Documents;

use App\Services\Reports\Output\ReportColumn as C;
use App\Services\Reports\Output\ReportDocument;
use App\Services\Reports\Output\ReportNotes;
use App\Services\Reports\Output\ReportSection;

/** Reporte de vendedores como documento exportable. */
final class SellerDocument
{
    /**
     * @param  array<string, mixed>  $r  la respuesta de SellerReport::build
     */
    public function build(array $r, string $filters): ReportDocument
    {
        $t = $r['totals'];
        $lv = fn (mixed $value, string $level): array => ['value' => $value, 'level' => $level];

        $rows = [];
        foreach ($r['rows'] as $row) {
            $s = $row['sales'];
            $sem = $row['semaphores'];
            $f = $row['follow_up'];
            $rows[] = [
                $row['rank'], $row['seller']['name'], $row['seller']['company'], $row['seller']['branch'],
                $s['net_total'], $lv($s['growth_pct'], $sem['growth_pct']), $s['documents'], $s['average_ticket'], $s['customers'],
                $row['goal']['goal'], $lv($row['goal']['attainment_pct'], $sem['goal_attainment']),
                $lv($s['discount_pct'], $sem['discount_pct']), $lv($s['below_list_pct'], $sem['below_list_pct']), $lv($s['top3_concentration_pct'], $sem['top3_concentration_pct']),
                $row['portfolio']['total'], $row['portfolio']['inactive'], $lv($row['portfolio']['lost_pct'], $sem['lost_customers_pct']),
                $row['credit']['balance'], $lv($row['credit']['overdue_pct'], $sem['overdue_pct']),
                $f['quotes_issued'], $lv($f['quote_conversion_pct'], $sem['quote_conversion_pct']),
                $f['has_user'] ? $f['contacts'] : 'sin usuario', $lv($f['overdue_follow_ups'], $sem['overdue_follow_ups']),
            ];
        }

        $columns = [
            new C('#', C::INT), new C('Vendedor'), new C('Compañía'), new C('Sucursal'),
            new C('Venta neta', C::MONEY), new C('Crecimiento', C::SIGNED_PCT, true), new C('Documentos', C::INT), new C('Ticket', C::MONEY), new C('Clientes', C::INT),
            new C('Meta del periodo', C::MONEY), new C('Cumplimiento de meta', C::PCT, true),
            new C('Descuento', C::PCT, true), new C('Bajo precio normal', C::PCT, true), new C('Top 3 clientes', C::PCT, true),
            new C('Clientes en cartera', C::INT), new C('Inactivos', C::INT), new C('Perdidos', C::PCT, true),
            new C('Por cobrar', C::MONEY), new C('Vencido', C::PCT, true),
            new C('Cotizaciones emitidas', C::INT), new C('Conversión de cotizaciones', C::PCT, true),
            new C('Contactos'), new C('Pendientes vencidos', C::INT, true),
        ];

        return new ReportDocument(
            'Reporte de vendedores', 'reporte-vendedores', Header::lines($r, $filters), ReportNotes::for($r['notes']),
            [
                ['label' => 'Venta neta', 'value' => $t['net_total'], 'kind' => C::MONEY],
                ['label' => 'Crecimiento contra la comparación', 'value' => $t['growth_pct'], 'kind' => C::SIGNED_PCT],
                ['label' => 'Documentos', 'value' => $t['documents'], 'kind' => C::INT],
                ['label' => 'Cumplimiento de meta', 'value' => $t['attainment_pct'], 'kind' => C::PCT],
                ['label' => 'Saldo vencido', 'value' => $t['credit_overdue'], 'kind' => C::MONEY],
                ['label' => 'Indicadores críticos', 'value' => $t['red_flags'], 'kind' => C::INT],
                ['label' => 'Contraste: venta consolidada', 'value' => $r['reconciliation']['consolidated_net'], 'kind' => C::MONEY],
                ['label' => 'Contraste: suma de los vendedores', 'value' => $r['reconciliation']['rows_net'], 'kind' => C::MONEY],
                ['label' => 'Contraste: diferencia', 'value' => $r['reconciliation']['net_difference'], 'kind' => C::MONEY],
            ],
            [new ReportSection('Evaluación de vendedores', $columns, $rows)],
        );
    }
}
