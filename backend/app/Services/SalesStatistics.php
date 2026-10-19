<?php

namespace App\Services;

use App\Models\SalesLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Agregaciones de ventas para el dashboard. Parte siempre de SalesLine, así el ámbito de compañía/sucursal
 * del usuario autenticado se aplica por el global scope. Los documentos se cuentan por `count(distinct)`:
 * una factura de varias líneas cuenta una sola vez dentro de cada grupo.
 */
class SalesStatistics
{
    /** Dimensiones de desglose: clave => [expresión de grupo, expresión de etiqueta, join necesario]. */
    private const DIMENSIONS = ['company', 'branch', 'seller', 'operator', 'category', 'product', 'customer', 'month', 'day'];

    /** Filas máximas del periodo de comparación al emparejar grupos. */
    private const COMPARISON_ROWS = 5000;

    /** Métricas por las que se puede ordenar un desglose. */
    public const SORTS = ['net_total', 'tax_total', 'grand_total', 'units', 'documents', 'customers', 'margin'];

    /** @return list<string> */
    public static function dimensions(): array
    {
        return self::DIMENSIONS;
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

        $current = $this->kpis($this->base($from, $to, $filters));
        $previous = $this->kpis($this->base($previousFrom, $previousTo, $filters));

        $first = $this->base(null, null, $filters)->min('sales_lines.issued_on');
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
            'change_pct' => $this->changes($current, $previous),
            'first_sale_date' => $firstDate,
            'warnings' => $warnings,
            'notes' => ['margin' => 'estimated_with_current_cost'],
        ];
    }

    /**
     * Desglose con la venta neta del periodo de comparación y su variación por fila (no aplica a mes ni día).
     *
     * @param  array<string, mixed>  $filters
     * @param  array{0: CarbonImmutable, 1: CarbonImmutable}  $compare
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
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

            return [...$row, 'previous_net_total' => $this->money($previous), 'net_change_pct' => $previous > 0 ? round(($now - $previous) / $previous * 100, 2) : null];
        }, $result['rows']);

        return $result;
    }

    /**
     * Desglose por una dimensión.
     *
     * @param  bool  $costedOnly  deja fuera los grupos sin ninguna línea con costo válido (para rankings de margen)
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function breakdown(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $sort, int $limit, bool $costedOnly = false): array
    {
        [$key, $label] = $this->dimensionColumns($dimension);

        $query = $this->base($from, $to, $filters);
        $this->joinForDimension($query, $dimension);

        $rows = $query
            ->selectRaw("{$key} as group_key, {$label} as group_label")
            ->selectRaw($this->aggregates())
            ->groupByRaw($key);

        if ($costedOnly) {
            $rows->havingRaw('sum(case when sales_lines.line_cost_total > 0 then 1 else 0 end) > 0');
        }

        $chronological = in_array($dimension, ['month', 'day'], true);
        // Los grupos sin dato de la métrica (p. ej. margen sin costo) van al final tanto en PostgreSQL como en SQLite.
        $rows->orderByRaw($chronological ? "{$key} asc" : $this->sortExpression($sort).' desc nulls last, group_key asc');

        // Se pide una fila de más para saber si hubo recorte.
        $result = $chronological ? $rows->toBase()->get() : $rows->limit($limit + 1)->toBase()->get();
        $truncated = ! $chronological && $result->count() > $limit;

        return [
            'rows' => $result->take($chronological ? PHP_INT_MAX : $limit)->map(fn ($r) => ['key' => (string) $r->group_key, 'label' => (string) $r->group_label, ...$this->format($r)])->values()->all(),
            'truncated' => $truncated,
        ];
    }

    /**
     * Rankings de producto o categoría por venta neta, unidades, frecuencia (facturas distintas que lo incluyen) y margen.
     * El de margen solo incluye grupos con costo válido y usa el costo actual.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, list<array<string, mixed>>>
     */
    public function rankings(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, int $limit): array
    {
        $top = fn (string $sort, bool $costed = false) => $this->breakdown($dimension, $from, $to, $filters, $sort, $limit, $costed)['rows'];

        return [
            'by_net_total' => $top('net_total'),
            'by_units' => $top('units'),
            'by_documents' => $top('documents'),
            'by_margin' => $top('margin', true),
        ];
    }

    /** @param  array<string, mixed>  $filters  */
    private function base(?CarbonImmutable $from, ?CarbonImmutable $to, array $filters): Builder
    {
        $query = SalesLine::query()->join('sales_documents as sd', 'sd.id', '=', 'sales_lines.sales_document_id');

        if ($from && $to) {
            // Medio abierto (>= desde, < hasta + 1 día): incluye el último día aunque el motor guarde la fecha con hora.
            $query->where('sales_lines.issued_on', '>=', $from->toDateString())
                ->where('sales_lines.issued_on', '<', $to->addDay()->toDateString());
        }

        foreach (['company_id' => 'sales_lines.company_id', 'branch_id' => 'sales_lines.branch_id', 'seller_id' => 'sd.seller_id', 'operator_id' => 'sd.operator_id'] as $filter => $column) {
            if (! empty($filters[$filter])) {
                $query->where($column, (int) $filters[$filter]);
            }
        }

        foreach (['category' => 'sales_lines.category_name', 'product_code' => 'sales_lines.product_code', 'customer_code' => 'sd.customer_code'] as $filter => $column) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                $query->where($column, $filters[$filter]);
            }
        }

        return $query;
    }

    /** @return array{0: string, 1: string} */
    private function dimensionColumns(string $dimension): array
    {
        $sqlite = DB::connection()->getDriverName() === 'sqlite';
        $month = $sqlite ? "strftime('%Y-%m', sales_lines.issued_on)" : "to_char(sales_lines.issued_on, 'YYYY-MM')";
        $day = $sqlite ? 'date(sales_lines.issued_on)' : "to_char(sales_lines.issued_on, 'YYYY-MM-DD')";

        return match ($dimension) {
            'company' => ['sales_lines.company_id', 'max(dim.name)'],
            'branch' => ['sales_lines.branch_id', 'max(dim.name)'],
            'seller' => ['sd.seller_id', 'max(dim.name)'],
            'operator' => ['sd.operator_id', 'max(dim.name)'],
            'category' => ['sales_lines.category_name', 'max(sales_lines.category_name)'],
            'product' => ['sales_lines.product_code', 'max(sales_lines.product_name)'],
            'customer' => ['sd.customer_code', 'max(sd.customer_name)'],
            'month' => [$month, $month],
            'day' => [$day, $day],
            default => throw new InvalidArgumentException("Dimensión no soportada: {$dimension}"),
        };
    }

    private function joinForDimension(Builder $query, string $dimension): void
    {
        $table = ['company' => ['companies', 'sales_lines.company_id'], 'branch' => ['branches', 'sales_lines.branch_id'],
            'seller' => ['sellers', 'sd.seller_id'], 'operator' => ['operators', 'sd.operator_id']][$dimension] ?? null;

        if ($table) {
            $query->join("{$table[0]} as dim", 'dim.id', '=', $table[1]);
        }
    }

    /** @param  Builder<SalesLine>  $query  @return array<string, mixed> */
    private function kpis(Builder $query): array
    {
        return $this->format($query->selectRaw($this->aggregates())->toBase()->first());
    }

    private function aggregates(): string
    {
        return implode(', ', [
            'coalesce(sum(sales_lines.net_total), 0) as net_total',
            'coalesce(sum(sales_lines.tax_total), 0) as tax_total',
            'coalesce(sum(sales_lines.grand_total), 0) as grand_total',
            'coalesce(sum(sales_lines.units), 0) as units',
            'count(distinct sales_lines.sales_document_id) as documents',
            'count(distinct sd.customer_code) as customers',
            // Margen solo sobre líneas con costo válido (> 0); el costo es el ACTUAL, no el de la fecha de venta.
            'coalesce(sum(case when sales_lines.line_cost_total > 0 then sales_lines.net_total end), 0) as costed_net',
            'coalesce(sum(case when sales_lines.line_cost_total > 0 then sales_lines.line_cost_total end), 0) as costed_cost',
        ]);
    }

    private function sortExpression(string $sort): string
    {
        return $sort === 'margin' ? '(sum(case when sales_lines.line_cost_total > 0 then sales_lines.net_total - sales_lines.line_cost_total end))'
            : ['net_total' => 'sum(sales_lines.net_total)', 'tax_total' => 'sum(sales_lines.tax_total)', 'grand_total' => 'sum(sales_lines.grand_total)',
                'units' => 'sum(sales_lines.units)', 'documents' => 'count(distinct sales_lines.sales_document_id)',
                'customers' => 'count(distinct sd.customer_code)'][$sort];
    }

    /** @return array<string, mixed> */
    private function format(object $r): array
    {
        $net = (float) $r->net_total;
        $documents = (int) $r->documents;
        $costedNet = (float) $r->costed_net;
        $margin = $costedNet - (float) $r->costed_cost;

        return [
            'net_total' => $this->money($net),
            'tax_total' => $this->money((float) $r->tax_total),
            'grand_total' => $this->money((float) $r->grand_total),
            'units' => $this->money((float) $r->units),
            'documents' => $documents,
            'customers' => (int) $r->customers,
            'average_ticket' => $documents > 0 ? $this->money($net / $documents) : null,
            'estimated_margin' => $costedNet > 0 ? $this->money($margin) : null,
            'estimated_margin_pct' => $costedNet > 0 ? round($margin / $costedNet * 100, 2) : null,
            'cost_coverage_pct' => $net > 0 ? round($costedNet / $net * 100, 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, float|null>
     */
    private function changes(array $current, array $previous): array
    {
        $out = [];
        foreach (['net_total', 'tax_total', 'grand_total', 'units', 'documents', 'customers', 'average_ticket', 'estimated_margin'] as $metric) {
            $before = $previous[$metric] === null ? 0.0 : (float) $previous[$metric];
            $now = $current[$metric] === null ? 0.0 : (float) $current[$metric];
            $out[$metric] = $before > 0 ? round(($now - $before) / $before * 100, 2) : null;
        }

        return $out;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
