<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SalesLine;
use App\Services\Sales\SalesMetrics;
use App\Services\Sales\SalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Agregaciones de ventas para el dashboard: periodos, comparaciones, desgloses y rankings. Las consultas SQL viven en `SalesQuery` y
 * el formato de cifras en `SalesMetrics`. Los documentos se cuentan por `count(distinct)`: una factura de varias líneas cuenta una
 * sola vez dentro de cada grupo.
 */
class SalesStatistics
{
    public const CATEGORY_SQL = SalesQuery::CATEGORY_SQL;

    public const GROUP_SQL = SalesQuery::GROUP_SQL;

    public const LINE_SQL = SalesQuery::LINE_SQL;

    /** Filas máximas del periodo de comparación al emparejar grupos. */
    private const COMPARISON_ROWS = 5000;

    public const SORTS = SalesQuery::SORTS;

    public function __construct(private readonly SalesQuery $query, private readonly SalesMetrics $metrics) {}

    /** @return list<string> */
    public static function dimensions(): array
    {
        return SalesQuery::DIMENSIONS;
    }

    /**
     * Periodo contra el que se compara: el indicado por el usuario o, por defecto, el equivalente anterior
     * (misma duración, inmediatamente antes).
     *
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $compare
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function comparisonRange(CarbonImmutable $from, CarbonImmutable $to, ?array $compare = null): array
    {
        if ($compare) {
            return $compare;
        }

        $days = (int) $from->diffInDays($to) + 1;
        $previousTo = $from->subDay();

        return [$previousTo->subDays($days - 1), $previousTo];
    }

    /**
     * KPI del periodo y del periodo de comparación, con avisos cuando la comparación no es equivalente.
     *
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}|null  $compare
     * @return array<string, mixed>
     */
    public function summary(CarbonImmutable $from, CarbonImmutable $to, array $filters, ?array $compare = null): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        [$previousFrom, $previousTo] = $this->comparisonRange($from, $to, $compare);
        $previousDays = (int) $previousFrom->diffInDays($previousTo) + 1;

        $current = $this->kpis($this->query->base($from, $to, $filters));
        $previous = $this->kpis($this->query->base($previousFrom, $previousTo, $filters));

        $first = $this->query->base(null, null, $filters)->min('sales_lines.issued_on');
        $firstDate = $first ? CarbonImmutable::parse($first)->toDateString() : null;   // SQLite devuelve la fecha con hora
        $warnings = [];
        if ($firstDate === null || $firstDate > $previousFrom->toDateString()) {
            $warnings[] = 'previous_period_incomplete';
        }
        if ($previousDays !== $days) {
            $warnings[] = 'different_length';
        }
        if ($previousFrom <= $to && $previousTo >= $from) {
            $warnings[] = 'overlapping_periods';
        }

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => $days],
            'previous_period' => ['from' => $previousFrom->toDateString(), 'to' => $previousTo->toDateString(), 'days' => $previousDays],
            'comparison' => $compare ? 'custom' : 'automatic',
            'current' => $current,
            'previous' => $previous,
            'change_pct' => $this->metrics->changes($current, $previous),
            'first_sale_date' => $firstDate,
            'warnings' => $warnings,
            'notes' => ['margin' => 'estimated_with_current_cost'],
        ];
    }

    /**
     * KPI de todo el historial disponible (sin periodo) con la primera y la última fecha de venta.
     *
     * @param  array<string, mixed>  $filters
     * @return array{kpis: array<string, mixed>, first: string|null, last: string|null}
     */
    public function overall(array $filters): array
    {
        $dates = $this->query->base(null, null, $filters)->toBase()->selectRaw('min(sales_lines.issued_on) as first, max(sales_lines.issued_on) as last')->first();

        return [
            'kpis' => $this->kpis($this->query->base(null, null, $filters)),
            // SQLite devuelve la fecha con hora.
            'first' => $dates?->first ? CarbonImmutable::parse($dates->first)->toDateString() : null,
            'last' => $dates?->last ? CarbonImmutable::parse($dates->last)->toDateString() : null,
        ];
    }

    /**
     * Desglose con la venta neta del periodo de comparación y su variación por fila (no aplica a mes ni día).
     *
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $compare
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
     */
    public function breakdownCompared(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $sort, int $limit, array $compare): array
    {
        $result = $this->breakdown($dimension, $from, $to, $filters, $sort, $limit);

        if (in_array($dimension, ['month', 'day'], true)) {
            return $result;
        }

        $before = collect($this->breakdown($dimension, $compare[0], $compare[1], $filters, 'net_total', self::COMPARISON_ROWS)['rows'])->keyBy('key');

        $result['rows'] = array_map(function (array $row) use ($before) {
            $previous = (float) ($before[$row['key']]['net_total'] ?? 0);
            $now = (float) $row['net_total'];

            return [...$row, 'previous_net_total' => $this->metrics->money($previous), 'net_change_pct' => $previous > 0 ? round(($now - $previous) / $previous * 100, 2) : null];
        }, $result['rows']);

        return $result;
    }

    /**
     * Desglose por una dimensión.
     *
     * @param  bool  $costedOnly  deja fuera los grupos sin ninguna línea con costo válido (para rankings de margen)
     * @param  bool  $discountedOnly  deja fuera los grupos sin descuento (para el ranking de descuento)
     * @param  array<string, mixed>  $filters
     * @return array{rows: array<int, array<string, mixed>>, truncated: bool}
     */
    public function breakdown(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $sort, int $limit, bool $costedOnly = false, bool $discountedOnly = false): array
    {
        [$key, $label] = $this->query->dimensionColumns($dimension);

        $query = $this->query->base($from, $to, $filters);
        $this->query->joinForDimension($query, $dimension);

        $rows = $query
            ->selectRaw("{$key} as group_key, {$label} as group_label")
            ->selectRaw($this->query->aggregates())
            ->groupByRaw($key);

        if ($costedOnly) {
            $rows->havingRaw('sum(case when sales_lines.line_cost_total > 0 then 1 else 0 end) > 0');
        }

        if ($discountedOnly) {
            $rows->havingRaw('coalesce(sum(sales_lines.discount_total), 0) > 0');
        }

        $chronological = in_array($dimension, ['month', 'day'], true);
        // Los grupos sin dato de la métrica (p. ej. margen sin costo) van al final tanto en PostgreSQL como en SQLite.
        $rows->orderByRaw($chronological ? "{$key} asc" : $this->query->sortExpression($sort).' desc nulls last, group_key asc');

        // Se pide una fila de más para saber si hubo recorte.
        $result = $chronological ? $rows->toBase()->get() : $rows->limit($limit + 1)->toBase()->get();
        $truncated = ! $chronological && $result->count() > $limit;

        return [
            'rows' => $result->take($chronological ? PHP_INT_MAX : $limit)->map(fn ($r) => ['key' => (string) $r->group_key, 'label' => (string) $r->group_label, ...$this->metrics->format($r)])->values()->all(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Rankings de producto o categoría por venta neta, unidades, frecuencia (facturas distintas que lo incluyen), margen y descuento.
     * El de margen solo incluye grupos con costo válido y usa el costo actual.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function rankings(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, int $limit): array
    {
        $top = fn (string $sort, bool $costed = false, bool $discounted = false) => $this->breakdown($dimension, $from, $to, $filters, $sort, $limit, $costed, $discounted)['rows'];

        return [
            'by_net_total' => $top('net_total'),
            'by_units' => $top('units'),
            'by_documents' => $top('documents'),
            'by_margin' => $top('margin', true),
            'by_discount' => $top('discount_total', discounted: true),
        ];
    }

    /**
     * @param  Builder<SalesLine>  $query
     * @return array<string, mixed>
     */
    private function kpis(Builder $query): array
    {
        return $this->metrics->format($query->selectRaw($this->query->aggregates())->toBase()->first() ?? throw new \LogicException('La consulta de totales no devolvió ninguna fila.'));
    }
}
