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
    /**
     * Categoría de una línea: Nivel 3 del catálogo de productos (compañía + código) y, si el producto no está en el
     * catálogo o no tiene Nivel 3, la categoría que trae el archivo de ventas.
     */
    public const CATEGORY_SQL = "coalesce(nullif(pr.level3, ''), sales_lines.category_name)";

    /** Grupo general (Nivel 1) y línea (Nivel 2) del catálogo de productos; sin catálogo o sin ese nivel, «Sin clasificar». */
    public const GROUP_SQL = "coalesce(nullif(pr.level1, ''), 'Sin clasificar')";

    public const LINE_SQL = "coalesce(nullif(pr.level2, ''), 'Sin clasificar')";

    private const DIMENSIONS = ['company', 'branch', 'seller', 'operator', 'group', 'line', 'category', 'product', 'customer', 'month', 'day'];

    /** Filas máximas del periodo de comparación al emparejar grupos. */
    private const COMPARISON_ROWS = 5000;

    /** Métricas por las que se puede ordenar un desglose. */
    public const SORTS = ['net_total', 'tax_total', 'grand_total', 'discount_total', 'units', 'documents', 'customers', 'margin'];

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
     * KPI de todo el historial disponible (sin periodo) con la primera y la última fecha de venta.
     *
     * @param  array<string, mixed>  $filters
     * @return array{kpis: array<string, mixed>, first: string|null, last: string|null}
     */
    public function overall(array $filters): array
    {
        $dates = $this->base(null, null, $filters)->toBase()->selectRaw('min(sales_lines.issued_on) as first, max(sales_lines.issued_on) as last')->first();

        return [
            'kpis' => $this->kpis($this->base(null, null, $filters)),
            // SQLite devuelve la fecha con hora.
            'first' => $dates->first ? CarbonImmutable::parse($dates->first)->toDateString() : null,
            'last' => $dates->last ? CarbonImmutable::parse($dates->last)->toDateString() : null,
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
     * @param  bool  $discountedOnly  deja fuera los grupos sin descuento (para el ranking de descuento)
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, truncated: bool}
     */
    public function breakdown(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $sort, int $limit, bool $costedOnly = false, bool $discountedOnly = false): array
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

        if ($discountedOnly) {
            $rows->havingRaw('coalesce(sum(sales_lines.discount_total), 0) > 0');
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
     * Rankings de producto o categoría por venta neta, unidades, frecuencia (facturas distintas que lo incluyen), margen y descuento.
     * El de margen solo incluye grupos con costo válido y usa el costo actual.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, list<array<string, mixed>>>
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

    /** @param  array<string, mixed>  $filters  */
    private function base(?CarbonImmutable $from, ?CarbonImmutable $to, array $filters): Builder
    {
        $query = SalesLine::query()
            ->join('sales_documents as sd', 'sd.id', '=', 'sales_lines.sales_document_id')
            ->leftJoin('products as pr', fn ($j) => $j->on('pr.company_id', '=', 'sales_lines.company_id')->on('pr.code', '=', 'sales_lines.product_code'));

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

        // Varios vendedores a la vez (equipo de un supervisor); una lista vacía no devuelve nada.
        if (isset($filters['seller_ids'])) {
            $query->whereIn('sd.seller_id', $filters['seller_ids']);
        }

        foreach (['group' => self::GROUP_SQL, 'line' => self::LINE_SQL, 'category' => self::CATEGORY_SQL, 'product_code' => 'sales_lines.product_code', 'customer_code' => 'sd.customer_code'] as $filter => $column) {
            if (isset($filters[$filter]) && $filters[$filter] !== '') {
                in_array($filter, ['group', 'line', 'category'], true) ? $query->whereRaw($column.' = ?', [$filters[$filter]]) : $query->where($column, $filters[$filter]);
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
            'group' => [self::GROUP_SQL, 'max('.self::GROUP_SQL.')'],
            'line' => [self::LINE_SQL, 'max('.self::LINE_SQL.')'],
            'category' => [self::CATEGORY_SQL, 'max('.self::CATEGORY_SQL.')'],
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
            // Descuento (ya restado de net_total). El % se calcula solo sobre líneas que traen Sub Total.
            'coalesce(sum(sales_lines.discount_total), 0) as discount_total',
            'coalesce(sum(case when sales_lines.subtotal is not null then sales_lines.subtotal end), 0) as discounted_gross',
            'coalesce(sum(case when sales_lines.subtotal is not null then coalesce(sales_lines.discount_total, 0) end), 0) as discounted_amount',
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
            : ['net_total' => 'sum(sales_lines.net_total)', 'tax_total' => 'sum(sales_lines.tax_total)', 'grand_total' => 'sum(sales_lines.grand_total)', 'discount_total' => 'sum(sales_lines.discount_total)',
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
            'discount_total' => $this->money((float) $r->discount_total),
            'discount_pct' => (float) $r->discounted_gross > 0 ? round((float) $r->discounted_amount / (float) $r->discounted_gross * 100, 2) : null,
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
        foreach (['net_total', 'tax_total', 'grand_total', 'discount_total', 'units', 'documents', 'customers', 'average_ticket', 'estimated_margin'] as $metric) {
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
