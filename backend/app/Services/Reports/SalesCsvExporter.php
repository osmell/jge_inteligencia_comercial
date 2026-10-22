<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;

/** Reportes de ventas, metas y ventas por cliente como CSV (UTF-8 con BOM, para que Excel lo lea bien). */
class SalesCsvExporter
{
    public function __construct(private readonly SalesReportTable $table) {}

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
        fputcsv($out, [SalesReportTable::LABELS[$dimension], 'Clave', ...array_values(SalesReportTable::METRICS), ...SalesReportTable::COMPARISON_HEADERS]);

        foreach ($this->table->rows($dimension, $from, $to, $filters, $compare) as $row) {
            fputcsv($out, [CsvSafe::value($row['label']), CsvSafe::value($row['key']), ...$this->table->metricValues($row), $row['previous_net_total'] ?? null, $row['net_change_pct'] ?? null]);
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
                CsvSafe::value($r['seller']), $r['goal_amount'], $r['days_off'], $r['starts_on'], $r['ends_on'], $r['eligible_days'],
                $r['effective_goal'], $r['actual'], $r['compliance_pct'], $status[$r['status']], $r['expected_to_date'], $r['pace_pct'],
                $r['projected'], $r['projected_pct'], $r['source'] === null ? null : ($r['source'] === 'suggested' ? 'Sugerida' : 'Manual'),
            ]);
        }
    }

    /**
     * Ventas por cliente como CSV (UTF-8 con BOM).
     *
     * @param  resource  $out
     * @param  list<array<string, mixed>>  $rows  filas de CustomerSalesReport
     */
    public function writeCustomerSalesCsv($out, array $rows): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, SalesReportTable::CUSTOMER_HEADERS);
        foreach ($rows as $r) {
            fputcsv($out, array_map(fn ($v) => is_string($v) ? CsvSafe::value($v) : $v, $this->table->customerSalesValues($r)));
        }
    }
}
