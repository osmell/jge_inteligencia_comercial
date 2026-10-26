<?php

declare(strict_types=1);

namespace App\Services\Reports\Output\Documents;

use App\Services\Reports\Output\ReportColumn as C;
use App\Services\Reports\Output\ReportDocument;
use App\Services\Reports\Output\ReportNotes;
use App\Services\Reports\Output\ReportSection;

/** Mezcla de productos como documento exportable: lo más facturado del nivel elegido y la amplitud de catálogo de cada persona. */
final class ProductMixDocument
{
    public const LEVELS = ['product' => 'producto', 'category' => 'categoría', 'line' => 'línea', 'group' => 'grupo'];

    /** @param  array<string, mixed>  $r  la respuesta de ProductMixReport::build */
    public function build(array $r, string $filters): ReportDocument
    {
        $level = self::LEVELS[$r['level']] ?? $r['level'];
        $who = $r['subject'] === 'operator' ? 'operador' : 'vendedor';
        $ranking = $r['ranking'];
        $t = $r['totals'];

        $items = [];
        foreach ($ranking['items'] as $item) {
            $items[] = [$item['rank'], $item['name'], $item['net_total'], $item['share_pct'], $item['growth_pct'], $item['units'], $item['documents'], $item['customers']];
        }
        $itemColumns = [new C('#', C::INT), new C(ucfirst($level)), new C('Venta neta', C::MONEY), new C('% del total', C::PCT), new C('Cambio', C::SIGNED_PCT), new C('Unidades', C::DECIMAL), new C('Documentos', C::INT), new C('Clientes', C::INT)];
        $itemNote = $ranking['truncated'] ? 'Se muestran los '.count($ranking['items'])." con más venta de {$ranking['total_items']}." : null;

        $people = [];
        foreach ($r['rows'] as $row) {
            $people[] = [
                $row['rank'], $row['subject']['name'], $row['net_total'], $row['share_pct'], $row['products_sold'],
                $row['categories_sold'], $row['categories_catalog'], $row['category_coverage_pct'], $row['lines_sold'], $row['lines_catalog'], $row['line_coverage_pct'],
                $row['groups_sold'], $row['top5_share_pct'],
            ];
        }
        $peopleColumns = [
            new C('#', C::INT), new C(ucfirst($who)), new C('Venta neta', C::MONEY), new C('% del total', C::PCT), new C('Productos distintos', C::INT),
            new C('Categorías vendidas', C::INT), new C('Categorías del catálogo', C::INT), new C('Cobertura de categorías', C::PCT),
            new C('Líneas vendidas', C::INT), new C('Líneas del catálogo', C::INT), new C('Cobertura de líneas', C::PCT), new C('Grupos vendidos', C::INT), new C('Top 5 productos', C::PCT),
        ];

        return new ReportDocument(
            'Mezcla de productos', 'reporte-mezcla-de-productos', [...Header::lines($r, $filters), "Se evalúa a: {$who}es · Nivel: {$level}"], ReportNotes::for($r['notes']),
            [
                ['label' => 'Venta neta', 'value' => $t['net_total'], 'kind' => C::MONEY],
                ['label' => 'Categorías del catálogo', 'value' => $t['catalog_categories'], 'kind' => C::INT],
                ['label' => 'Líneas del catálogo', 'value' => $t['catalog_lines'], 'kind' => C::INT],
                ['label' => 'Cobertura promedio de categorías', 'value' => $t['average_category_coverage_pct'], 'kind' => C::PCT],
                ['label' => 'Con menos de la mitad del catálogo', 'value' => $t['below_half_coverage'], 'kind' => C::INT],
                ['label' => 'Contraste: venta consolidada', 'value' => $r['reconciliation']['consolidated_net'], 'kind' => C::MONEY],
                ['label' => 'Contraste: suma de las personas', 'value' => $r['reconciliation']['rows_net'], 'kind' => C::MONEY],
                ['label' => 'Contraste: diferencia', 'value' => $r['reconciliation']['net_difference'], 'kind' => C::MONEY],
            ],
            [
                new ReportSection("Lo más facturado por {$level}", $itemColumns, $items, $itemNote),
                new ReportSection("Amplitud de catálogo por {$who}", $peopleColumns, $people, 'Cobertura: porcentaje de las categorías (o líneas) del catálogo de su compañía en las que tuvo venta positiva. Top 5: porcentaje de su venta que sale de sus 5 productos principales.'),
            ],
        );
    }
}
