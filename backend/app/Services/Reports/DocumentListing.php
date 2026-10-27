<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Sales\SalesBasis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Detalle de facturación de un vendedor, operador o sucursal: cada documento del periodo con sus totales, para contrastarlo con la cifra consolidada. */
class DocumentListing
{
    public const PER_PAGE = 50;

    /** Columna del documento por la que se filtra cada tipo de evaluado (lista blanca). */
    private const COLUMNS = ['seller' => 'sd.seller_id', 'operator' => 'sd.operator_id', 'branch' => 'sd.branch_id'];

    /**
     * @param  list<int>|null  $companyIds
     * @param  'seller'|'operator'|'branch'  $dimension
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function documents(?array $companyIds, string $dimension, int $id, string $name, CarbonImmutable $from, CarbonImmutable $to, array $filters, int $page): array
    {
        $documents = fn (): Builder => DB::table('sales_documents as sd')
            ->where(self::COLUMNS[$dimension], $id)
            ->where('sd.issued_on', '>=', $from->toDateString())->where('sd.issued_on', '<', $to->addDay()->toDateString())
            ->when($companyIds !== null, fn ($q) => $q->whereIn('sd.company_id', (array) $companyIds))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('sd.branch_id', (int) $filters['branch_id']));

        $totals = $documents()->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sd.id')
            ->tap(fn ($q) => SalesBasis::scope($q, 'sl'))
            ->selectRaw('coalesce(sum('.SalesBasis::net('sl').'), 0) as net, coalesce(sum('.SalesBasis::tax('sl').'), 0) as tax, coalesce(sum('.SalesBasis::grand('sl').'), 0) as grand')->first();
        $count = $documents()->count();

        $rows = $documents()
            ->join('branches as b', 'b.id', '=', 'sd.branch_id')
            ->join('operators as o', 'o.id', '=', 'sd.operator_id')
            ->join('sellers as s', 's.id', '=', 'sd.seller_id')
            ->leftJoin('sales_lines as sl', 'sl.sales_document_id', '=', 'sd.id')
            ->tap(fn ($q) => SalesBasis::scope($q, 'sl'))
            ->selectRaw('sd.id, sd.series, sd.number, sd.issued_on, sd.customer_code, sd.customer_name, b.name as branch, o.name as operator, s.name as seller, count(sl.id) as lines, coalesce(sum('.SalesBasis::net('sl').'), 0) as net_total, coalesce(sum('.SalesBasis::tax('sl').'), 0) as tax_total, coalesce(sum('.SalesBasis::grand('sl').'), 0) as grand_total')
            ->groupBy('sd.id', 'sd.series', 'sd.number', 'sd.issued_on', 'sd.customer_code', 'sd.customer_name', 'b.name', 'o.name', 's.name')
            ->orderByDesc('sd.issued_on')->orderByDesc('sd.id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        return [
            'subject' => ['id' => $id, 'name' => $name],
            'rows' => $rows->map(fn ($r) => [
                'id' => (int) $r->id, 'document' => $r->series.'-'.$r->number, 'issued_on' => substr((string) $r->issued_on, 0, 10),
                'customer_code' => $r->customer_code, 'customer_name' => $r->customer_name, 'branch' => $r->branch, 'operator' => $r->operator, 'seller' => $r->seller,
                'lines' => (int) $r->lines, 'net_total' => round((float) $r->net_total, 2), 'tax_total' => round((float) $r->tax_total, 2), 'grand_total' => round((float) $r->grand_total, 2),
            ])->all(),
            'totals' => ['documents' => $count, 'net_total' => round((float) $totals?->net, 2), 'tax_total' => round((float) $totals?->tax, 2), 'grand_total' => round((float) $totals?->grand, 2)],
            'page' => $page, 'per_page' => self::PER_PAGE, 'last_page' => max(1, (int) ceil($count / self::PER_PAGE)),
        ];
    }
}
