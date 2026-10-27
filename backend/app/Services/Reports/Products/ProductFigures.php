<?php

declare(strict_types=1);

namespace App\Services\Reports\Products;

use App\Services\Sales\SalesBasis;
use App\Services\Sales\SalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Consultas del reporte de mezcla de productos: qué se factura (producto, categoría, línea o grupo) en total y por vendedor u operador.
 * Toda venta es NETA (sin IVA) y las columnas SQL salen de listas blancas (`SalesQuery`, `GROUP_COLUMNS`), nunca del texto del usuario.
 */
class ProductFigures
{
    /** Niveles del catálogo por los que se puede ver la venta, de lo más fino a lo más general. */
    public const LEVELS = ['product', 'category', 'line', 'group'];

    public const SUBJECTS = ['seller' => 'sd.seller_id', 'operator' => 'sd.operator_id'];

    /** Productos individuales que se listan como máximo (el catálogo llega a miles); los demás niveles se listan completos. */
    public const PRODUCT_LIMIT = 200;

    public function __construct(private readonly SalesQuery $query) {}

    /**
     * Venta por elemento del nivel pedido, de mayor a menor.
     *
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters  admite `seller_id` / `operator_id` para ver solo a una persona
     * @return list<array{key: string, name: string, net_total: float, units: float, documents: int, customers: int}>
     */
    public function ranking(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $level, ?int $limit = null): array
    {
        if (! in_array($level, self::LEVELS, true)) {
            throw new InvalidArgumentException("Nivel no soportado: {$level}");
        }

        [$column, $name] = $this->query->dimensionColumns($level);
        $query = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->selectRaw("{$column} as item_key, {$name} as item_name")
            ->selectRaw('coalesce(sum('.SalesBasis::net('sales_lines').'), 0) as net, coalesce(sum(sales_lines.units), 0) as units')
            ->selectRaw('count(distinct sales_lines.sales_document_id) as documents, count(distinct sd.customer_code) as customers')
            ->groupByRaw($column)
            ->orderByRaw('sum('.SalesBasis::net('sales_lines').") desc, {$column} asc");
        if ($limit !== null) {
            $query->limit($limit);
        }

        return array_values($query->toBase()->get()->map(fn ($r) => [
            'key' => (string) $r->item_key, 'name' => (string) $r->item_name, 'net_total' => round((float) $r->net, 2),
            'units' => round((float) $r->units, 2), 'documents' => (int) $r->documents, 'customers' => (int) $r->customers,
        ])->all());
    }

    /**
     * Venta neta total del periodo (la base sobre la que se calcula el peso de cada elemento).
     *
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     */
    public function netTotal(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters): float
    {
        return round((float) $this->query->baseForCompanies($from, $to, $filters, $companyIds)->selectRaw('coalesce(sum('.SalesBasis::net('sales_lines').'), 0) as net')->toBase()->value('net'), 2);
    }

    /**
     * Cuántos elementos distintos hay en la venta del periodo (para decir «mostrando 200 de N»).
     *
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     */
    public function distinctItems(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $level): int
    {
        [$column] = $this->query->dimensionColumns($level);

        return (int) $this->query->baseForCompanies($from, $to, $filters, $companyIds)->selectRaw("count(distinct {$column}) as n")->toBase()->value('n');
    }

    /**
     * Venta, documentos y productos distintos de cada vendedor u operador, y lo que pesan sus 5 productos principales.
     *
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<int, array{net_total: float, documents: int, products_sold: int, top5_net: float}>
     */
    public function subjectTotals(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $subject): array
    {
        $column = self::SUBJECTS[$subject];
        $totals = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->selectRaw("{$column} as subject_id")
            ->selectRaw('coalesce(sum('.SalesBasis::net('sales_lines').'), 0) as net, count(distinct sales_lines.sales_document_id) as documents, count(distinct sales_lines.product_code) as products')
            ->groupByRaw($column)->toBase()->get();

        $perProduct = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->selectRaw("{$column} as subject_id, sales_lines.product_code as code, sum(".SalesBasis::net('sales_lines').') as net')
            ->groupByRaw("{$column}, sales_lines.product_code")->toBase();
        $ranked = DB::query()->fromSub($perProduct, 't')
            ->selectRaw('t.subject_id, t.net, row_number() over (partition by t.subject_id order by t.net desc, t.code asc) as rn');
        $top = DB::query()->fromSub($ranked, 'r')->where('r.rn', '<=', 5)
            ->selectRaw('r.subject_id, sum(r.net) as top_net')->groupBy('r.subject_id')->pluck('top_net', 'subject_id');

        $out = [];
        foreach ($totals as $r) {
            $out[(int) $r->subject_id] = [
                'net_total' => round((float) $r->net, 2), 'documents' => (int) $r->documents, 'products_sold' => (int) $r->products,
                'top5_net' => round((float) ($top[$r->subject_id] ?? 0), 2),
            ];
        }

        return $out;
    }

    /**
     * Elementos de un nivel (categoría, línea o grupo) con venta positiva de cada vendedor u operador.
     *
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<int, list<string>> id → elementos distintos que vendió
     */
    public function itemsSold(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $subject, string $level): array
    {
        [$item] = $this->query->dimensionColumns($level);
        $column = self::SUBJECTS[$subject];

        $out = [];
        $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->selectRaw("{$column} as subject_id, {$item} as item")
            ->groupByRaw("{$column}, {$item}")->havingRaw('sum('.SalesBasis::net('sales_lines').') > 0')
            ->toBase()->get()
            ->each(function ($r) use (&$out) {
                $out[(int) $r->subject_id][] = (string) $r->item;
            });

        return $out;
    }

    /**
     * Lo que ofrece el catálogo de cada compañía: categorías (nivel 3), líneas (nivel 2) y grupos (nivel 1) con al menos un producto.
     *
     * @param  list<int>|null  $companyIds
     * @return array<int, array{categories: list<string>, lines: list<string>, groups: list<string>}> compañía → catálogo
     */
    public function catalog(?array $companyIds): array
    {
        $rows = DB::table('products')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', (array) $companyIds))
            ->get(['company_id', 'level1', 'level2', 'level3']);

        $out = [];
        foreach ($rows->groupBy('company_id') as $companyId => $products) {
            $distinct = fn (string $level) => array_values($products->pluck($level)->filter(fn ($v) => is_string($v) && $v !== '')->unique()->sort()->all());
            $out[(int) $companyId] = ['categories' => $distinct('level3'), 'lines' => $distinct('level2'), 'groups' => $distinct('level1')];
        }

        return $out;
    }
}
