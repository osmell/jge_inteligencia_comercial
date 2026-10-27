<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\SalesLine;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Consultas SQL de las estadísticas de ventas: la consulta base con sus filtros, las dimensiones de desglose, las agregaciones y el
 * orden. Parte siempre de SalesLine, así el ámbito de compañía/sucursal del usuario autenticado se aplica por el global scope.
 * Todo fragmento de SQL sale de listas blancas de esta clase; los valores del usuario van siempre como parámetros.
 */
class SalesQuery
{
    /**
     * Categoría de una línea: Nivel 3 del catálogo de productos (compañía + código) y, si el producto no está en el
     * catálogo o no tiene Nivel 3, la categoría que trae el archivo de ventas.
     */
    public const CATEGORY_SQL = "coalesce(nullif(pr.level3, ''), sales_lines.category_name)";

    /** Grupo general (Nivel 1) y línea (Nivel 2) del catálogo de productos; sin catálogo o sin ese nivel, «Sin clasificar». */
    public const GROUP_SQL = "coalesce(nullif(pr.level1, ''), 'Sin clasificar')";

    public const LINE_SQL = "coalesce(nullif(pr.level2, ''), 'Sin clasificar')";

    public const DIMENSIONS = ['company', 'branch', 'seller', 'operator', 'group', 'line', 'category', 'product', 'customer', 'month', 'day'];

    /** Métricas por las que se puede ordenar un desglose. */
    public const SORTS = ['net_total', 'tax_total', 'grand_total', 'discount_total', 'units', 'documents', 'customers', 'margin'];

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<SalesLine>
     */
    public function base(?CarbonImmutable $from, ?CarbonImmutable $to, array $filters): Builder
    {
        return $this->baseOn(SalesLine::query(), $from, $to, $filters);
    }

    /**
     * Igual que `base`, pero limitado solo por compañía (sin el límite de sucursales del usuario): los reportes de evaluación
     * muestran a cada vendedor, operador y sucursal con TODA su venta en las compañías a las que la persona tiene acceso.
     *
     * @param  list<int>|null  $companyIds  null = todas (administración)
     * @param  array<string, mixed>  $filters
     * @return Builder<SalesLine>
     */
    public function baseForCompanies(?CarbonImmutable $from, ?CarbonImmutable $to, array $filters, ?array $companyIds): Builder
    {
        $query = SalesLine::withoutGlobalScopes();
        if ($companyIds !== null) {
            $query->whereIn('sales_lines.company_id', $companyIds);
        }

        return $this->baseOn($query, $from, $to, $filters);
    }

    /**
     * @param  Builder<SalesLine>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<SalesLine>
     */
    private function baseOn(Builder $query, ?CarbonImmutable $from, ?CarbonImmutable $to, array $filters): Builder
    {
        $query
            ->join('sales_documents as sd', 'sd.id', '=', 'sales_lines.sales_document_id')
            ->leftJoin('products as pr', fn ($j) => $j->on('pr.company_id', '=', 'sales_lines.company_id')->on('pr.code', '=', 'sales_lines.product_code'));
        SalesBasis::scope($query, 'sales_lines', withCost: true);

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

        // Varias sucursales a la vez (las que una persona puede ver en los reportes); una lista vacía no devuelve nada.
        if (isset($filters['branch_ids'])) {
            $query->whereIn('sales_lines.branch_id', $filters['branch_ids']);
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
    public function dimensionColumns(string $dimension): array
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

    /**
     * @param  Builder<SalesLine>  $query
     */
    public function joinForDimension(Builder $query, string $dimension): void
    {
        $table = ['company' => ['companies', 'sales_lines.company_id'], 'branch' => ['branches', 'sales_lines.branch_id'],
            'seller' => ['sellers', 'sd.seller_id'], 'operator' => ['operators', 'sd.operator_id']][$dimension] ?? null;

        if ($table) {
            $query->join("{$table[0]} as dim", 'dim.id', '=', $table[1]);
        }
    }

    public function aggregates(): string
    {
        $net = SalesBasis::net();
        $cost = SalesBasis::cost();

        return implode(', ', [
            "coalesce(sum({$net}), 0) as net_total",
            'coalesce(sum('.SalesBasis::tax().'), 0) as tax_total',
            'coalesce(sum('.SalesBasis::grand().'), 0) as grand_total',
            // Descuento (ya restado de net_total). El % se calcula solo sobre líneas que traen Sub Total.
            'coalesce(sum(sales_lines.discount_total), 0) as discount_total',
            'coalesce(sum(case when sales_lines.subtotal is not null then sales_lines.subtotal end), 0) as discounted_gross',
            'coalesce(sum(case when sales_lines.subtotal is not null then coalesce(sales_lines.discount_total, 0) end), 0) as discounted_amount',
            'coalesce(sum(sales_lines.units), 0) as units',
            'count(distinct sales_lines.sales_document_id) as documents',
            'count(distinct sd.customer_code) as customers',
            // Margen solo sobre líneas con costo válido (> 0); el costo es el ACTUAL, no el de la fecha de venta.
            "coalesce(sum(case when {$cost} > 0 then {$net} end), 0) as costed_net",
            "coalesce(sum(case when {$cost} > 0 then {$cost} end), 0) as costed_cost",
        ]);
    }

    public function sortExpression(string $sort): string
    {
        return $sort === 'margin' ? '(sum(case when '.SalesBasis::cost().' > 0 then '.SalesBasis::net().' - '.SalesBasis::cost().' end))'
            : ['net_total' => 'sum('.SalesBasis::net().')', 'tax_total' => 'sum('.SalesBasis::tax().')', 'grand_total' => 'sum('.SalesBasis::grand().')', 'discount_total' => 'sum(sales_lines.discount_total)',
                'units' => 'sum(sales_lines.units)', 'documents' => 'count(distinct sales_lines.sales_document_id)',
                'customers' => 'count(distinct sd.customer_code)'][$sort];
    }
}
