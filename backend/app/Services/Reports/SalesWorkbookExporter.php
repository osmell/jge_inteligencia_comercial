<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\SalesStatistics;
use Carbon\CarbonImmutable;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Reportes de ventas y de ventas por cliente como Excel. Los importes salen como números (sin formato) para poder sumarlos. */
class SalesWorkbookExporter
{
    public function __construct(private readonly SalesStatistics $stats, private readonly SalesReportTable $table, private readonly FilterDescriber $describer) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $compare
     * @return string ruta de un archivo temporal (el llamador lo elimina)
     */
    public function buildWorkbook(CarbonImmutable $from, CarbonImmutable $to, array $filters, string $generatedBy, ?array $compare = null): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        $bold = (new Style)->withFontBold(true);
        $header = fn (array $values) => Row::fromValuesWithStyle($values, $bold);

        $writer = new Writer;
        $writer->openToFile($path);

        $summary = $this->stats->summary($from, $to, $filters, $compare);
        $writer->getCurrentSheet()->setName('Resumen');
        $writer->addRow($header(['Reporte de ventas']));
        $writer->addRows([
            Row::fromValues(['Periodo', "{$summary['period']['from']} a {$summary['period']['to']}"]),
            Row::fromValues([$summary['comparison'] === 'custom' ? 'Periodo comparado' : 'Periodo anterior', "{$summary['previous_period']['from']} a {$summary['previous_period']['to']}"]),
            Row::fromValues(['Filtros', $this->describer->describe($filters)]),
            Row::fromValues(['Generado por', $generatedBy]),
            Row::fromValues(['Generado el', now()->format('Y-m-d H:i')]),
            Row::fromValues(['Margen', 'Estimado con el costo actual (no el histórico), solo sobre líneas con costo válido.']),
        ]);
        if (in_array('previous_period_incomplete', $summary['warnings'], true)) {
            $writer->addRow(Row::fromValues(['Aviso', "El historial empieza el {$summary['first_sale_date']}: la comparación es parcial."]));
        }
        foreach (SalesReportTable::COMPARISON_WARNINGS as $code => $text) {
            if (in_array($code, $summary['warnings'], true)) {
                $writer->addRow(Row::fromValues(['Aviso', $text]));
            }
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($header(['Indicador', 'Periodo', 'Periodo comparado', 'Variación %']));
        foreach (['net_total', 'tax_total', 'grand_total', 'discount_total', 'units', 'documents', 'customers', 'average_ticket', 'estimated_margin'] as $metric) {
            $writer->addRow(Row::fromValues([
                SalesReportTable::METRICS[$metric],
                $this->table->number($summary['current'][$metric]), $this->table->number($summary['previous'][$metric]), $summary['change_pct'][$metric],
            ]));
        }

        foreach (SalesStatistics::dimensions() as $dimension) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Por '.mb_strtolower(SalesReportTable::LABELS[$dimension]));
            $writer->addRow($header([SalesReportTable::LABELS[$dimension], 'Clave', ...array_values(SalesReportTable::METRICS), ...SalesReportTable::COMPARISON_HEADERS]));
            foreach ($this->table->rows($dimension, $from, $to, $filters, $compare) as $row) {
                $writer->addRow(Row::fromValues([$row['label'], $row['key'], ...array_map($this->table->number(...), $this->table->metricValues($row)), $this->table->number($row['previous_net_total'] ?? null), $row['net_change_pct'] ?? null]));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * Ventas por cliente como Excel (hoja de datos y hoja de notas).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return string ruta de un archivo temporal (el llamador lo elimina)
     */
    public function buildCustomerSalesWorkbook(array $rows, CarbonImmutable $from, CarbonImmutable $to, string $filters, string $generatedBy): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        $bold = (new Style)->withFontBold(true);
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('Ventas por cliente');
        $writer->addRow(Row::fromValuesWithStyle(SalesReportTable::CUSTOMER_HEADERS, $bold));
        foreach ($rows as $r) {
            $values = $this->table->customerSalesValues($r);
            foreach ([6, 9] as $i) {
                $values[$i] = $this->table->number($values[$i]);
            }
            $writer->addRow(Row::fromValues($values));
        }
        $writer->addNewSheetAndMakeItCurrent()->setName('Notas');
        $writer->addRows([
            Row::fromValues(['Periodo', "{$from->toDateString()} a {$to->toDateString()}"]),
            Row::fromValues(['Filtros', $filters]),
            Row::fromValues(['Generado por', $generatedBy]),
            Row::fromValues(['Generado el', now()->format('Y-m-d H:i')]),
            Row::fromValues(['Atribución', 'La venta es del vendedor; si vendedor y facturador tienen el mismo nombre es una venta directa del facturador.']),
            Row::fromValues(['Devoluciones', 'Aún no se descuentan: falta el formato de notas de crédito.']),
        ]);
        $writer->close();

        return $path;
    }
}
