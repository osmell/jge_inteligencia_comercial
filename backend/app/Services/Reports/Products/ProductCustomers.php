<?php

declare(strict_types=1);

namespace App\Services\Reports\Products;

use App\Services\Sales\SalesBasis;
use App\Services\Sales\SalesQuery;
use Carbon\CarbonImmutable;

/** Los clientes detrás de la cifra «Clientes» de la mezcla de productos: quién compró un producto, categoría, línea o grupo en el periodo. */
class ProductCustomers
{
    /** Filtro de venta de cada nivel del catálogo (lista blanca; `SalesQuery` pone la columna SQL). */
    private const FILTERS = ['product' => 'product_code', 'category' => 'category', 'line' => 'line', 'group' => 'group'];

    public function __construct(private readonly SalesQuery $query, private readonly ProductFigures $figures) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters  admite `seller_id` / `operator_id`
     * @return array<string, mixed>
     */
    public function for(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $level, string $key): array
    {
        $item = collect($this->figures->ranking($companyIds, $from, $to, $filters + [self::FILTERS[$level] => $key], $level))->firstWhere('key', $key);

        $rows = $this->query->baseForCompanies($from, $to, $filters + [self::FILTERS[$level] => $key], $companyIds)
            ->selectRaw('sd.customer_code as code, max(sd.customer_name) as name')
            ->selectRaw('coalesce(sum('.SalesBasis::net('sales_lines').'), 0) as net, coalesce(sum(sales_lines.units), 0) as units')
            ->selectRaw('count(distinct sales_lines.sales_document_id) as documents, max(sales_lines.issued_on) as last_on')
            ->groupBy('sd.customer_code')
            ->orderByRaw('sum('.SalesBasis::net('sales_lines').') desc, sd.customer_code asc')
            ->toBase()->get();

        return [
            'level' => $level,
            'item' => ['key' => $key, 'name' => $item['name'] ?? $key],
            'rows' => $rows->map(fn ($r) => [
                'code' => (string) $r->code, 'name' => (string) $r->name, 'documents' => (int) $r->documents,
                'units' => round((float) $r->units, 2), 'net_total' => round((float) $r->net, 2), 'last_purchase' => substr((string) $r->last_on, 0, 10),
            ])->all(),
            'totals' => ['customers' => $rows->count(), 'net_total' => round((float) $rows->sum('net'), 2)],
        ];
    }
}
