<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Operator;
use App\Models\Seller;
use Carbon\CarbonImmutable;
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
        'category' => 'Categoría', 'product' => 'Producto', 'customer' => 'Cliente', 'month' => 'Mes', 'day' => 'Día',
    ];

    private const METRICS = [
        'net_total' => 'Venta neta', 'tax_total' => 'Impuesto', 'grand_total' => 'Total facturado', 'units' => 'Unidades',
        'documents' => 'Documentos', 'customers' => 'Clientes', 'average_ticket' => 'Ticket promedio',
        'estimated_margin' => 'Margen estimado', 'estimated_margin_pct' => 'Margen estimado %', 'cost_coverage_pct' => 'Cobertura de costo %',
    ];

    public function __construct(private readonly SalesStatistics $stats) {}

    /**
     * Escribe un desglose como CSV (UTF-8 con BOM, para que Excel lo lea bien).
     *
     * @param  resource  $out
     * @param  array<string, mixed>  $filters
     */
    public function writeCsv($out, string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters): void
    {
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, [self::LABELS[$dimension], 'Clave', ...array_values(self::METRICS)]);

        foreach ($this->rows($dimension, $from, $to, $filters) as $row) {
            fputcsv($out, [self::csvSafe($row['label']), self::csvSafe($row['key']), ...$this->metricValues($row)]);
        }
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return string ruta de un archivo temporal (el llamador lo elimina)
     */
    public function buildWorkbook(CarbonImmutable $from, CarbonImmutable $to, array $filters, string $generatedBy): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        $bold = (new Style)->withFontBold(true);
        $header = fn (array $values) => Row::fromValuesWithStyle($values, $bold);

        $writer = new Writer;
        $writer->openToFile($path);

        $summary = $this->stats->summary($from, $to, $filters);
        $writer->getCurrentSheet()->setName('Resumen');
        $writer->addRow($header(['Reporte de ventas']));
        $writer->addRows([
            Row::fromValues(['Periodo', "{$summary['period']['from']} a {$summary['period']['to']}"]),
            Row::fromValues(['Periodo anterior', "{$summary['previous_period']['from']} a {$summary['previous_period']['to']}"]),
            Row::fromValues(['Filtros', $this->describeFilters($filters)]),
            Row::fromValues(['Generado por', $generatedBy]),
            Row::fromValues(['Generado el', now()->format('Y-m-d H:i')]),
            Row::fromValues(['Margen', 'Estimado con el costo actual (no el histórico), solo sobre líneas con costo válido.']),
        ]);
        if (in_array('previous_period_incomplete', $summary['warnings'], true)) {
            $writer->addRow(Row::fromValues(['Aviso', "El historial empieza el {$summary['first_sale_date']}: la comparación con el periodo anterior es parcial."]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow($header(['Indicador', 'Periodo', 'Periodo anterior', 'Variación %']));
        foreach (['net_total', 'tax_total', 'grand_total', 'units', 'documents', 'customers', 'average_ticket', 'estimated_margin'] as $metric) {
            $writer->addRow(Row::fromValues([
                self::METRICS[$metric],
                $this->number($summary['current'][$metric]), $this->number($summary['previous'][$metric]), $summary['change_pct'][$metric],
            ]));
        }

        foreach (SalesStatistics::dimensions() as $dimension) {
            $writer->addNewSheetAndMakeItCurrent()->setName('Por '.mb_strtolower(self::LABELS[$dimension]));
            $writer->addRow($header([self::LABELS[$dimension], 'Clave', ...array_values(self::METRICS)]));
            foreach ($this->rows($dimension, $from, $to, $filters) as $row) {
                $writer->addRow(Row::fromValues([$row['label'], $row['key'], ...array_map($this->number(...), $this->metricValues($row))]));
            }
        }

        $writer->close();

        return $path;
    }

    /** @param  array<string, mixed>  $filters  @return list<array<string, mixed>> */
    private function rows(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        return $this->stats->breakdown($dimension, $from, $to, $filters, 'net_total', self::MAX_ROWS)['rows'];
    }

    /** @param  array<string, mixed>  $row  @return list<mixed> */
    private function metricValues(array $row): array
    {
        return array_map(fn (string $metric) => $row[$metric], array_keys(self::METRICS));
    }

    private function number(mixed $value): int|float|null
    {
        return $value === null ? null : (is_int($value) ? $value : (float) $value);
    }

    /** @param  array<string, mixed>  $filters */
    private function describeFilters(array $filters): string
    {
        $names = [
            'company_id' => ['Compañía', fn ($id) => Company::find($id)?->name],
            'branch_id' => ['Sucursal', fn ($id) => Branch::find($id)?->name],
            'seller_id' => ['Vendedor', fn ($id) => Seller::find($id)?->name],
            'operator_id' => ['Operador', fn ($id) => Operator::find($id)?->name],
            'category' => ['Categoría', null], 'product_code' => ['Producto', null], 'customer_code' => ['Cliente', null],
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
    private static function csvSafe(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
