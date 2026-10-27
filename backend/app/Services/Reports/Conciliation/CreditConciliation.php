<?php

declare(strict_types=1);

namespace App\Services\Reports\Conciliation;

use App\Services\Sales\SalesBasis;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Facturas de crédito contra los documentos de venta a los que quedaron enlazadas: mismo cliente, mismo neto (subtotal menos descuento)
 * y mismo impuesto, con la tolerancia de las importaciones. Es la misma comprobación que hace la importación de crédito, pero sobre lo
 * que hoy está guardado.
 */
class CreditConciliation
{
    public const CODE = 'credit_invoice_mismatch';

    /**
     * @param  list<int>|null  $companyIds
     * @return array{linked: int, mismatched: int, unlinked: int}
     */
    public function counts(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $invoices = fn (): Builder => DB::table('credit_invoices as ci')
            ->where('ci.issued_on', '>=', $from->toDateString())->where('ci.issued_on', '<', $to->addDay()->toDateString())
            ->when($companyIds !== null, fn ($q) => $q->whereIn('ci.company_id', (array) $companyIds));

        return [
            'linked' => $invoices()->whereNotNull('ci.sales_document_id')->count(),
            'mismatched' => DB::query()->fromSub($this->mismatches($companyIds, $from, $to), 'issues')->count(),
            'unlinked' => $invoices()->whereNull('ci.sales_document_id')->count(),
        ];
    }

    /**
     * @param  list<int>|null  $companyIds
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function issues(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, int $page): array
    {
        $query = $this->mismatches($companyIds, $from, $to);
        $total = DB::query()->fromSub($query, 'issues')->count();
        $rows = DB::query()->fromSub($query, 'issues')->orderByDesc('issued_on')->orderByDesc('id')->offset(($page - 1) * SalesIntegrityChecks::PER_PAGE)->limit(SalesIntegrityChecks::PER_PAGE)->get();

        return [
            'rows' => array_values($rows->map(function ($r) {
                $problems = [];
                if ($r->customer_code !== $r->sale_customer) {
                    $problems[] = "cliente {$r->customer_code} en crédito y {$r->sale_customer} en ventas";
                }
                if (abs((float) $r->credit_net - (float) $r->sale_net) > $this->tolerance()) {
                    $problems[] = sprintf('neto %.2f en crédito y %.2f en ventas', (float) $r->credit_net, (float) $r->sale_net);
                }
                if (abs((float) $r->credit_tax - (float) $r->sale_tax) > $this->tolerance()) {
                    $problems[] = sprintf('impuesto %.2f en crédito y %.2f en ventas', (float) $r->credit_tax, (float) $r->sale_tax);
                }

                return [
                    'id' => (int) $r->id, 'document' => $r->series.'-'.$r->number, 'issued_on' => substr((string) $r->issued_on, 0, 10),
                    'customer_name' => $r->customer_name, 'branch' => $r->branch, 'detail' => 'La factura de crédito no coincide con la venta: '.implode('; ', $problems).'.',
                ];
            })->all()),
            'total' => $total, 'page' => $page, 'per_page' => SalesIntegrityChecks::PER_PAGE, 'last_page' => max(1, (int) ceil($total / SalesIntegrityChecks::PER_PAGE)),
        ];
    }

    private function tolerance(): float
    {
        return (float) config('imports.amount_tolerance');
    }

    /** @param  list<int>|null  $companyIds */
    private function mismatches(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        $tolerance = $this->tolerance();

        return DB::table('credit_invoices as ci')
            ->join('sales_documents as sd', 'sd.id', '=', 'ci.sales_document_id')
            ->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sd.id')
            ->tap(fn ($q) => SalesBasis::scope($q, 'sl'))
            ->join('branches as b', 'b.id', '=', 'ci.branch_id')
            ->where('ci.issued_on', '>=', $from->toDateString())->where('ci.issued_on', '<', $to->addDay()->toDateString())
            ->when($companyIds !== null, fn ($q) => $q->whereIn('ci.company_id', (array) $companyIds))
            ->selectRaw('ci.id, ci.series, ci.number, ci.issued_on, ci.customer_name, ci.customer_code, b.name as branch, sd.customer_code as sale_customer, (ci.subtotal - ci.discount_total) as credit_net, ci.tax_total as credit_tax, sum('.SalesBasis::net('sl').') as sale_net, sum('.SalesBasis::tax('sl').') as sale_tax')
            ->groupBy('ci.id', 'ci.series', 'ci.number', 'ci.issued_on', 'ci.customer_name', 'ci.customer_code', 'b.name', 'sd.customer_code', 'ci.subtotal', 'ci.discount_total', 'ci.tax_total')
            ->havingRaw('ci.customer_code <> sd.customer_code or abs((ci.subtotal - ci.discount_total) - sum('.SalesBasis::net('sl').')) > cast(? as numeric) or abs(ci.tax_total - sum('.SalesBasis::tax('sl').')) > cast(? as numeric)', [$tolerance, $tolerance]);
    }
}
