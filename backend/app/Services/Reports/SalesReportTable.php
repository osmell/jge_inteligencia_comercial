<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\SalesStatistics;
use Carbon\CarbonImmutable;

/** Tabla común de los reportes de ventas: títulos, métricas y filas de cada desglose, iguales en CSV, Excel y PDF. */
class SalesReportTable
{
    /** Máximo de filas por desglose en un archivo exportado. */
    public const MAX_ROWS = 5000;

    public const LABELS = [
        'company' => 'Compañía', 'branch' => 'Sucursal', 'seller' => 'Vendedor', 'operator' => 'Operador',
        'group' => 'Grupo (Nivel 1)', 'line' => 'Línea (Nivel 2)', 'category' => 'Categoría', 'product' => 'Producto', 'customer' => 'Cliente', 'month' => 'Mes', 'day' => 'Día',
    ];

    public const METRICS = [
        'net_total' => 'Venta neta', 'tax_total' => 'Impuesto', 'grand_total' => 'Total facturado', 'discount_total' => 'Descuento', 'discount_pct' => 'Descuento %', 'units' => 'Unidades',
        'documents' => 'Documentos', 'customers' => 'Clientes', 'average_ticket' => 'Ticket promedio',
        'estimated_margin' => 'Margen estimado', 'estimated_margin_pct' => 'Margen estimado %', 'cost_coverage_pct' => 'Cobertura de costo %',
    ];

    public const COMPARISON_HEADERS = ['Venta neta periodo comparado', 'Variación venta neta %'];

    public const COMPARISON_WARNINGS = [
        'different_length' => 'Los periodos comparados tienen distinta duración: los totales no son equivalentes.',
        'overlapping_periods' => 'Los periodos comparados se traslapan.',
    ];

    public const CUSTOMER_HEADERS = [
        'Compañía', 'Código', 'Cliente', 'Tipo', 'Vendedor asignado', 'Supervisor', 'Venta neta', 'Documentos', 'Última compra',
        'Venta directa del facturador', 'Quién vendió', 'Quién facturó',
    ];

    public function __construct(private readonly SalesStatistics $stats) {}

    /**
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $compare
     * @return array<int, array<string, mixed>>
     */
    public function rows(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, ?array $compare): array
    {
        return $this->stats->breakdownCompared($dimension, $from, $to, $filters, 'net_total', self::MAX_ROWS, $this->stats->comparisonRange($from, $to, $compare))['rows'];
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<mixed>
     */
    public function metricValues(array $row): array
    {
        return array_map(fn (string $metric) => $row[$metric], array_keys(self::METRICS));
    }

    public function number(mixed $value): int|float|null
    {
        return $value === null ? null : (is_int($value) ? $value : (float) $value);
    }

    /**
     * @param  array<string, mixed>  $r
     * @return list<mixed>
     */
    public function customerSalesValues(array $r): array
    {
        $people = fn (array $list) => implode('; ', array_map(fn ($p) => "{$p['name']} ({$p['net_total']})", $list));

        return [
            $r['company'], $r['code'], $r['name'], $r['type'], $r['assigned_seller'], $r['supervisor'], $r['net_total'], $r['documents'],
            $r['last_purchase'], $r['direct_net_total'], $people($r['sellers']), $people($r['operators']),
        ];
    }
}
