<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Operator;
use App\Models\Seller;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Exporta los reportes de ventas a CSV (un desglose) o Excel (resumen + todos los desgloses).
 * Usa las mismas consultas que el dashboard, así que respeta el ámbito del usuario y las cifras coinciden con la pantalla.
 * Los importes salen como números (sin formato) para que se puedan sumar en Excel.
 */
class SalesReportExporter
{
    /** Máximo de filas por desglose en un archivo exportado. */
    public const MAX_ROWS = 5000;

    public const LABELS = [
        'company' => 'Compañía', 'branch' => 'Sucursal', 'seller' => 'Vendedor', 'operator' => 'Operador',
        'group' => 'Grupo (Nivel 1)', 'line' => 'Línea (Nivel 2)', 'category' => 'Categoría', 'product' => 'Producto', 'customer' => 'Cliente', 'month' => 'Mes', 'day' => 'Día',
    ];

    private const METRICS = [
        'net_total' => 'Venta neta', 'tax_total' => 'Impuesto', 'grand_total' => 'Total facturado', 'discount_total' => 'Descuento', 'discount_pct' => 'Descuento %', 'units' => 'Unidades',
        'documents' => 'Documentos', 'customers' => 'Clientes', 'average_ticket' => 'Ticket promedio',
        'estimated_margin' => 'Margen estimado', 'estimated_margin_pct' => 'Margen estimado %', 'cost_coverage_pct' => 'Cobertura de costo %',
    ];

    private const COMPARISON_HEADERS = ['Venta neta periodo comparado', 'Variación venta neta %'];

    private const COMPARISON_WARNINGS = [
        'different_length' => 'Los periodos comparados tienen distinta duración: los totales no son equivalentes.',
        'overlapping_periods' => 'Los periodos comparados se traslapan.',
    ];

    public function __construct(private readonly SalesStatistics $stats) {}

    /**
     * Escribe un desglose como CSV (UTF-8 con BOM, para que Excel lo lea bien).
     *
     * @param  resource  $out
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $compare
     */
    public function writeCsv($out, string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, ?array $compare = null): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [self::LABELS[$dimension], 'Clave', ...array_values(self::METRICS), ...self::COMPARISON_HEADERS]);

        foreach ($this->rows($dimension, $from, $to, $filters, $compare) as $row) {
            fputcsv($out, [self::csvSafe($row['label']), self::csvSafe($row['key']), ...$this->metricValues($row), $row['previous_net_total'] ?? null, $row['net_change_pct'] ?? null]);
        }
    }

    /**
     * Metas de un mes como CSV (UTF-8 con BOM).
     *
     * @param  resource  $out
     * @param  list<array<string, mixed>>  $rows  filas de SellerGoals::progress()
     */
    public function writeGoalsCsv($out, array $rows): void
    {
        $status = ['met' => 'Meta cumplida', 'warning' => 'Cerca de la meta', 'danger' => 'Por debajo', 'no_goal' => 'Sin meta', 'exempt' => 'Exento'];

        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Vendedor', 'Meta del mes', 'Días de ausencia', 'Alta', 'Baja', 'Días elegibles', 'Meta efectiva', 'Venta neta', 'Cumplimiento %', 'Estado', 'Esperado a la fecha', 'Ritmo %', 'Proyección', 'Proyección %', 'Origen de la meta']);

        foreach ($rows as $r) {
            fputcsv($out, [
                self::csvSafe($r['seller']), $r['goal_amount'], $r['days_off'], $r['starts_on'], $r['ends_on'], $r['eligible_days'],
                $r['effective_goal'], $r['actual'], $r['compliance_pct'], $status[$r['status']], $r['expected_to_date'], $r['pace_pct'],
                $r['projected'], $r['projected_pct'], $r['source'] === null ? null : ($r['source'] === 'suggested' ? 'Sugerida' : 'Manual'),
            ]);
        }
    }

    private const CUSTOMER_REPORT_HEADERS = [
        'Compañía', 'Código', 'Cliente', 'Tipo', 'Vendedor asignado', 'Supervisor', 'Venta neta', 'Documentos', 'Última compra',
        'Venta directa del facturador', 'Quién vendió', 'Quién facturó',
    ];

    /**
     * Ventas por cliente como CSV (UTF-8 con BOM).
     *
     * @param  resource  $out
     * @param  list<array<string, mixed>>  $rows  filas de CustomerSalesReport
     */
    public function writeCustomerSalesCsv($out, array $rows): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, self::CUSTOMER_REPORT_HEADERS);
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn ($v) => is_string($v) ? self::csvSafe($v) : $v, $this->customerSalesValues($r)));
        }
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
        $writer->addRow(Row::fromValuesWithStyle(self::CUSTOMER_REPORT_HEADERS, $bold));
        foreach ($rows as $r) {
            $values = $this->customerSalesValues($r);
            foreach ([6, 9] as $i) {
                $values[$i] = $this->number($values[$i]);
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

    /**
     * @param  array<string, mixed>  $r
     * @return list<mixed>
     */
    private function customerSalesValues(array $r): array
    {
        $people = fn (array $list) => implode('; ', array_map(fn ($p) => "{$p['name']} ({$p['net_total']})", $list));

        return [
            $r['company'], $r['code'], $r['name'], $r['type'], $r['assigned_seller'], $r['supervisor'], $r['net_total'], $r['documents'],
            $r['last_purchase'], $r['direct_net_total'], $people($r['sellers']), $people($r['operators']),
        ];
    }

    /** Dimensiones que resume el PDF (mes y día quedan para Excel/CSV). */
    public const PDF_DIMENSIONS = ['company', 'branch', 'seller', 'category', 'product', 'customer'];

    /** Filas por sección en el PDF. */
    public const PDF_ROWS = 15;

    /**
     * Reporte de ventas en PDF: indicadores y las primeras filas de los desgloses principales.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $compare
     * @return string contenido del PDF
     */
    public function buildSalesPdf(CarbonImmutable $from, CarbonImmutable $to, array $filters, string $generatedBy, ?array $compare = null): string
    {
        $summary = $this->stats->summary($from, $to, $filters, $compare);

        $warnings = [];
        if (in_array('previous_period_incomplete', $summary['warnings'], true)) {
            $warnings[] = "El historial empieza el {$summary['first_sale_date']}: la comparación es parcial.";
        }
        foreach (self::COMPARISON_WARNINGS as $code => $text) {
            if (in_array($code, $summary['warnings'], true)) {
                $warnings[] = $text;
            }
        }

        $sections = [];
        foreach (self::PDF_DIMENSIONS as $dimension) {
            $rows = $this->rows($dimension, $from, $to, $filters, $compare);
            $sections[] = [
                'title' => 'Por '.mb_strtolower(self::LABELS[$dimension]), 'label' => self::LABELS[$dimension],
                'rows' => array_slice($rows, 0, self::PDF_ROWS), 'total' => count($rows), 'compared' => isset($rows[0]) && array_key_exists('previous_net_total', $rows[0]),
                'note' => in_array($dimension, ['category', 'product'], true) ? 'Una factura con varias líneas puede aparecer en varios grupos: los documentos no se suman entre filas.' : null,
            ];
        }

        return $this->renderPdf('pdf.sales', [
            'summary' => $summary, 'warnings' => $warnings, 'sections' => $sections, 'filters' => $this->describeFilters($filters),
            'metrics' => array_intersect_key(self::METRICS, array_flip(['net_total', 'tax_total', 'grand_total', 'discount_total', 'units', 'documents', 'customers', 'average_ticket', 'estimated_margin'])),
        ], $generatedBy, 'landscape');
    }

    /**
     * Metas de un mes en PDF.
     *
     * @param  array{month: string, state: string, rows: list<array<string, mixed>>, totals: array<string, mixed>}  $progress
     */
    public function buildGoalsPdf(array $progress, string $generatedBy): string
    {
        return $this->renderPdf('pdf.goals', [
            'month' => $progress['month'], 'state' => $progress['state'], 'rows' => $progress['rows'], 'totals' => $progress['totals'],
            'thresholds' => ['warning_pct' => SellerGoals::WARNING_PCT, 'goal_pct' => SellerGoals::GOAL_PCT, 'pace_alert_pct' => SellerGoals::PACE_ALERT_PCT],
        ], $generatedBy, 'landscape');
    }

    /** @param  array<string, mixed>  $data */
    private function renderPdf(string $view, array $data, string $generatedBy, string $orientation): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);   // el contenido viene de archivos importados: nunca se descargan recursos externos
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view($view, $data + ['generatedBy' => $generatedBy, 'generatedAt' => now()->format('Y-m-d H:i')])->render());
        $pdf->setPaper('A4', $orientation);
        $pdf->render();

        return (string) $pdf->output();
    }

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
            Row::fromValues(['Filtros', $this->describeFilters($filters)]),
            Row::fromValues(['Generado por', $generatedBy]),
            Row::fromValues(['Generado el', now()->format('Y-m-d H:i')]),
            Row::fromValues(['Margen', 'Estimado con el costo actual (no el histórico), solo sobre líneas con costo válido.']),
        ]);
        if (in_array('previous_period_incomplete', $summary['warnings'], true)) {
            $writer->addRow(Row::fromValues(['Aviso', "El historial empieza el {$summary['first_sale_date']}: la comparación es parcial."]));
        }
        foreach (self::COMPARISON_WARNINGS as $code => $text) {
            if (in_array($code, $summary['warnings'], true)) {
                $writer->addRow(Row::fromValues(['Aviso', $text]));
            }
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($header(['Indicador', 'Periodo', 'Periodo comparado', 'Variación %']));
        foreach (['net_total', 'tax_total', 'grand_total', 'discount_total', 'units', 'documents', 'customers', 'average_ticket', 'estimated_margin'] as $metric) {
            $writer->addRow(Row::fromValues([
                self::METRICS[$metric],
                $this->number($summary['current'][$metric]), $this->number($summary['previous'][$metric]), $summary['change_pct'][$metric],
            ]));
        }

        foreach (SalesStatistics::dimensions() as $dimension) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Por '.mb_strtolower(self::LABELS[$dimension]));
            $writer->addRow($header([self::LABELS[$dimension], 'Clave', ...array_values(self::METRICS), ...self::COMPARISON_HEADERS]));
            foreach ($this->rows($dimension, $from, $to, $filters, $compare) as $row) {
                $writer->addRow(Row::fromValues([$row['label'], $row['key'], ...array_map($this->number(...), $this->metricValues($row)), $this->number($row['previous_net_total'] ?? null), $row['net_change_pct'] ?? null]));
            }
        }

        $writer->close();

        return $path;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $compare
     * @return list<array<string, mixed>>
     */
    private function rows(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, ?array $compare): array
    {
        return $this->stats->breakdownCompared($dimension, $from, $to, $filters, 'net_total', self::MAX_ROWS, $this->stats->comparisonRange($from, $to, $compare))['rows'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    private function metricValues(array $row): array
    {
        return array_map(fn (string $metric) => $row[$metric], array_keys(self::METRICS));
    }

    private function number(mixed $value): int|float|null
    {
        return $value === null ? null : (is_int($value) ? $value : (float) $value);
    }

    /** @param  array<string, mixed>  $filters */
    public function describeFilters(array $filters): string
    {
        $names = [
            'company_id' => ['Compañía', fn ($id) => Company::find($id)?->name],
            'branch_id' => ['Sucursal', fn ($id) => Branch::find($id)?->name],
            'seller_id' => ['Vendedor', fn ($id) => Seller::find($id)?->name],
            'operator_id' => ['Operador', fn ($id) => Operator::find($id)?->name],
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

    /** Evita que Excel interprete como fórmula un texto que viene de los archivos importados. */
    public static function csvSafe(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
