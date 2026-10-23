<?php

declare(strict_types=1);

namespace App\Services\Reports\Sellers;

use App\Models\Seller;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Detalle de facturación de un vendedor: cada documento del periodo con sus totales, para contrastarlo con la cifra consolidada. */
class SellerBilling
{
    public const PER_PAGE = 50;

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function documents(?array $companyIds, Seller $seller, CarbonImmutable $from, CarbonImmutable $to, array $filters, int $page): array
    {
        $documents = fn (): Builder => DB::table('sales_documents as sd')
            ->where('sd.seller_id', $seller->id)
            ->where('sd.issued_on', '>=', $from->toDateString())->where('sd.issued_on', '<', $to->addDay()->toDateString())
            ->when($companyIds !== null, fn ($q) => $q->whereIn('sd.company_id', (array) $companyIds))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('sd.branch_id', (int) $filters['branch_id']));

        $totals = $documents()->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sd.id')
            ->selectRaw('coalesce(sum(sl.net_total), 0) as net, coalesce(sum(sl.tax_total), 0) as tax, coalesce(sum(sl.grand_total), 0) as grand')->first();
        $count = $documents()->count();

        $rows = $documents()
            ->join('branches as b', 'b.id', '=', 'sd.branch_id')
            ->join('operators as o', 'o.id', '=', 'sd.operator_id')
            ->leftJoin('sales_lines as sl', 'sl.sales_document_id', '=', 'sd.id')
            ->selectRaw('sd.id, sd.series, sd.number, sd.issued_on, sd.customer_code, sd.customer_name, b.name as branch, o.name as operator, count(sl.id) as lines, coalesce(sum(sl.net_total), 0) as net_total, coalesce(sum(sl.tax_total), 0) as tax_total, coalesce(sum(sl.grand_total), 0) as grand_total')
            ->groupBy('sd.id', 'sd.series', 'sd.number', 'sd.issued_on', 'sd.customer_code', 'sd.customer_name', 'b.name', 'o.name')
            ->orderByDesc('sd.issued_on')->orderByDesc('sd.id')
            ->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        return [
            'seller' => ['id' => $seller->id, 'name' => $seller->name],
            'rows' => $rows->map(fn ($r) => [
                'id' => (int) $r->id, 'document' => $r->series.'-'.$r->number, 'issued_on' => substr((string) $r->issued_on, 0, 10),
                'customer_code' => $r->customer_code, 'customer_name' => $r->customer_name, 'branch' => $r->branch, 'operator' => $r->operator,
                'lines' => (int) $r->lines, 'net_total' => round((float) $r->net_total, 2), 'tax_total' => round((float) $r->tax_total, 2), 'grand_total' => round((float) $r->grand_total, 2),
            ])->all(),
            'totals' => ['documents' => $count, 'net_total' => round((float) $totals?->net, 2), 'tax_total' => round((float) $totals?->tax, 2), 'grand_total' => round((float) $totals?->grand, 2)],
            'page' => $page, 'per_page' => self::PER_PAGE, 'last_page' => max(1, (int) ceil($count / self::PER_PAGE)),
        ];
    }
}
