<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\SalesLine;
use App\Services\Sales\SalesBasis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Consulta del catálogo de productos y su cobertura frente a las ventas de los últimos 12 meses.
 * El catálogo se limita por compañía y las ventas por el ámbito (compañía/sucursal) del usuario.
 */
class ProductCatalog
{
    public const PER_PAGE = 25;

    public const SORTS = ['name', 'code', 'net_total'];

    /**
     * @param  array{q?: string|null, company_id?: int|null, level1?: string|null, level2?: string|null, level3?: string|null}  $f
     * @return array{rows: array<int, array<string, mixed>>, has_more: bool}
     */
    public function search(array $f, string $sort, int $page, ?CarbonImmutable $today = null): array
    {
        $sales = SalesLine::query()->tap(fn ($q) => SalesBasis::scope($q))->toBase()
            ->where('sales_lines.issued_on', '>=', $this->since($today)->toDateString())
            ->selectRaw('sales_lines.company_id as company_id, sales_lines.product_code as code, sum('.SalesBasis::net('sales_lines').') as net_total')
            ->groupBy('sales_lines.company_id', 'sales_lines.product_code');

        $query = Product::query()
            ->join('companies', 'companies.id', '=', 'products.company_id')
            ->leftJoinSub($sales, 's', fn ($j) => $j->on('s.company_id', '=', 'products.company_id')->on('s.code', '=', 'products.code'))
            ->when($f['company_id'] ?? null, fn ($q, $id) => $q->where('products.company_id', $id));

        foreach (['level1', 'level2', 'level3'] as $level) {
            if (($f[$level] ?? null) !== null && $f[$level] !== '') {
                $f[$level] === self::NONE ? $query->whereRaw("coalesce(products.{$level}, '') = ''") : $query->where("products.{$level}", $f[$level]);
            }
        }

        $term = trim((string) ($f['q'] ?? ''));
        if ($term !== '') {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($term)).'%';
            $query->where(fn ($q) => $q
                ->whereRaw("lower(products.code) like ? escape '\\'", [$like])
                ->orWhereRaw("lower(products.name) like ? escape '\\'", [$like]));
        }

        $order = match ($sort) {
            'code' => 'products.code asc',
            'net_total' => 'coalesce(s.net_total, 0) desc',
            default => 'products.name asc',
        };

        $rows = $query->toBase()
            ->selectRaw('products.company_id as company_id, companies.name as company, products.code as code, products.name as name')
            ->selectRaw('products.level1 as level1, products.level2 as level2, products.level3 as level3, coalesce(s.net_total, 0) as net_total')
            ->orderByRaw($order.', products.name asc, products.code asc')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)
            ->get();

        return [
            'rows' => $rows->take(self::PER_PAGE)->map(fn ($r) => [
                'company_id' => (int) $r->company_id, 'company' => (string) $r->company, 'code' => (string) $r->code, 'name' => (string) $r->name,
                'level1' => $r->level1, 'level2' => $r->level2, 'level3' => $r->level3, 'net_total' => number_format((float) $r->net_total, 2, '.', ''),
            ])->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /** Valor de filtro para «sin ese nivel». */
    public const NONE = '__none__';

    /**
     * Valores disponibles de cada nivel, acotados por los niveles superiores ya elegidos.
     *
     * @return array{level1: list<string>, level2: list<string>, level3: list<string>}
     */
    public function levels(?int $companyId, ?string $level1, ?string $level2): array
    {
        $base = fn () => Product::query()->toBase()->when($companyId, fn ($q) => $q->where('company_id', $companyId));
        $distinct = fn ($q, string $col) => $q->whereNotNull($col)->where($col, '!=', '')->distinct()->orderBy($col)->pluck($col)->map(fn ($v) => (string) $v)->all();

        return [
            'level1' => $distinct($base(), 'level1'),
            'level2' => $distinct($base()->when($level1, fn ($q) => $q->where('level1', $level1)), 'level2'),
            'level3' => $distinct($base()->when($level1, fn ($q) => $q->where('level1', $level1))->when($level2, fn ($q) => $q->where('level2', $level2)), 'level3'),
        ];
    }

    /**
     * Productos vendidos en los últimos 12 meses que no están en el catálogo (compañía + código), de más a menos venta.
     *
     * @return array{rows: array<int, array<string, mixed>>, has_more: bool}
     */
    public function missing(int $page, ?CarbonImmutable $today = null): array
    {
        $rows = $this->soldWithoutCatalog($today)
            ->join('companies', 'companies.id', '=', 'sales_lines.company_id')
            ->selectRaw('sales_lines.company_id as company_id, max(companies.name) as company, sales_lines.product_code as code, max(sales_lines.product_name) as name')
            ->selectRaw('max(sales_lines.category_name) as category, sum('.SalesBasis::net('sales_lines').') as net_total')
            ->groupBy('sales_lines.company_id', 'sales_lines.product_code')
            ->orderByRaw('sum('.SalesBasis::net('sales_lines').') desc')->orderBy('sales_lines.product_code')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE + 1)
            ->get();

        return [
            'rows' => $rows->take(self::PER_PAGE)->map(fn ($r) => [
                'company_id' => (int) $r->company_id, 'company' => (string) $r->company, 'code' => (string) $r->code, 'name' => (string) $r->name,
                'category' => $r->category, 'net_total' => number_format((float) $r->net_total, 2, '.', ''),
            ])->values()->all(),
            'has_more' => $rows->count() > self::PER_PAGE,
        ];
    }

    /**
     * Cobertura del catálogo sobre las ventas de los últimos 12 meses.
     *
     * @return array<string, mixed>
     */
    public function coverage(?CarbonImmutable $today = null): array
    {
        $since = $this->since($today)->toDateString();
        $all = SalesLine::query()->tap(fn ($q) => SalesBasis::scope($q))->toBase()->where('sales_lines.issued_on', '>=', $since);
        $soldProducts = (clone $all)->selectRaw('count(distinct sales_lines.company_id || \'|\' || sales_lines.product_code) as n')->value('n');
        $net = (float) (clone $all)->sum(DB::raw(SalesBasis::net()));

        $missing = $this->soldWithoutCatalog($today);
        $missingProducts = (clone $missing)->selectRaw('count(distinct sales_lines.company_id || \'|\' || sales_lines.product_code) as n')->value('n');
        $missingNet = (float) $missing->sum(DB::raw(SalesBasis::net()));

        return [
            'catalog_products' => Product::query()->count(),
            'sold_products' => (int) $soldProducts,
            'missing_products' => (int) $missingProducts,
            'net_total' => number_format($net, 2, '.', ''),
            'missing_net_total' => number_format($missingNet, 2, '.', ''),
            'covered_pct' => $net > 0 ? round(($net - $missingNet) / $net * 100, 2) : null,
        ];
    }

    private function soldWithoutCatalog(?CarbonImmutable $today): Builder
    {
        return SalesLine::query()->tap(fn ($q) => SalesBasis::scope($q))->toBase()
            ->leftJoin('products as pr', fn ($j) => $j->on('pr.company_id', '=', 'sales_lines.company_id')->on('pr.code', '=', 'sales_lines.product_code'))
            ->whereNull('pr.id')
            ->where('sales_lines.issued_on', '>=', $this->since($today)->toDateString());
    }

    private function since(?CarbonImmutable $today): CarbonImmutable
    {
        return ($today ?? CarbonImmutable::today())->subYear()->addDay();
    }
}
