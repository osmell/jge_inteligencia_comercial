<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\SalesStatistics;
use App\Services\SellerGoals;
use Carbon\CarbonImmutable;
use Dompdf\Dompdf;
use Dompdf\Options;

/** Reportes de ventas y de metas en PDF. */
class SalesPdfExporter
{
    /** Dimensiones que resume el PDF (mes y día quedan para Excel/CSV). */
    public const PDF_DIMENSIONS = ['company', 'branch', 'seller', 'category', 'product', 'customer'];

    /** Filas por sección en el PDF. */
    public const PDF_ROWS = 15;

    public function __construct(private readonly SalesStatistics $stats, private readonly SalesReportTable $table, private readonly FilterDescriber $describer) {}

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
        foreach (SalesReportTable::COMPARISON_WARNINGS as $code => $text) {
            if (in_array($code, $summary['warnings'], true)) {
                $warnings[] = $text;
            }
        }

        $sections = [];
        foreach (self::PDF_DIMENSIONS as $dimension) {
            $rows = $this->table->rows($dimension, $from, $to, $filters, $compare);
            $sections[] = [
                'title' => 'Por '.mb_strtolower(SalesReportTable::LABELS[$dimension]), 'label' => SalesReportTable::LABELS[$dimension],
                'rows' => array_slice($rows, 0, self::PDF_ROWS), 'total' => count($rows), 'compared' => isset($rows[0]) && array_key_exists('previous_net_total', $rows[0]),
                'note' => in_array($dimension, ['category', 'product'], true) ? 'Una factura con varias líneas puede aparecer en varios grupos: los documentos no se suman entre filas.' : null,
            ];
        }

        return $this->renderPdf('pdf.sales', [
            'summary' => $summary, 'warnings' => $warnings, 'sections' => $sections, 'filters' => $this->describer->describe($filters),
            'metrics' => array_intersect_key(SalesReportTable::METRICS, array_flip(['net_total', 'tax_total', 'grand_total', 'discount_total', 'units', 'documents', 'customers', 'average_ticket', 'estimated_margin'])),
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

    /**
     * @param  view-string  $view
     * @param  array<string, mixed>  $data
     */
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
}
