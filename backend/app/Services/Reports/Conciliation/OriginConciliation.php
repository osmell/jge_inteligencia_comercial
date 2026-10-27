<?php

declare(strict_types=1);

namespace App\Services\Reports\Conciliation;

use App\Models\Seller;
use App\Models\SellerSalesSummary;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Venta neta oficial del sistema de origen (resumen por vendedor) contra la que calcula el sistema, vendedor por vendedor
 * (docs/30_CONCILIACION_FACTURACION.md). Por compañía se toma el último corte cargado hasta el fin del periodo; el periodo del corte es el del
 * propio resumen (del `period_from` al corte), no el que se filtra en pantalla.
 *
 * Se muestran las dos bases a la vez, sin depender del selector de cifras: la venta importada tal cual y la corregida (equivalencias
 * corregidas y sin facturas anuladas). El origen cuenta como facturas también las anuladas.
 */
class OriginConciliation
{
    public const OK = 'ok';

    public const NEAR = 'near';

    public const DIFFERS = 'differs';

    /**
     * @param  list<int>|null  $companyIds
     * @return list<array<string, mixed>>
     */
    public function build(?array $companyIds, CarbonImmutable $to): array
    {
        $latest = SellerSalesSummary::withoutGlobalScopes()
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', $companyIds))
            ->where('cutoff_on', '<=', $to->toDateString())
            ->selectRaw('company_id, max(cutoff_on) as cutoff_on')->groupBy('company_id')->get();

        $cuts = [];
        foreach ($latest as $cut) {
            $cuts[] = $this->cut((int) $cut->company_id, CarbonImmutable::parse($cut->cutoff_on));
        }

        return $cuts;
    }

    /** @return array<string, mixed> */
    private function cut(int $companyId, CarbonImmutable $cutoff): array
    {
        $erp = SellerSalesSummary::withoutGlobalScopes()->where('company_id', $companyId)->where('cutoff_on', $cutoff->toDateString())->get();
        $from = $erp->min('period_from') ?? $cutoff;
        $from = CarbonImmutable::parse($from);

        $system = $this->system($companyId, $from, $cutoff);
        $rows = [];
        foreach ($erp as $r) {
            $s = $system[$r->normalized_name] ?? null;
            unset($system[$r->normalized_name]);
            $rows[] = $this->row($r->seller_name, (float) $r->net_sale, (int) $r->invoices_count, $s);
        }
        foreach ($system as $s) { // vendedores con venta en el sistema que el resumen del origen no trae
            $rows[] = $this->row($s['name'], 0.0, 0, $s);
        }
        usort($rows, fn (array $a, array $b) => abs($b['difference']) <=> abs($a['difference']) ?: strcmp($a['seller'], $b['seller']));

        $sum = fn (string $key) => array_sum(array_column($rows, $key));
        $voidedWithoutSale = $this->voidedWithoutSale($companyId, $from, $cutoff);
        $totals = $this->row('Total', $sum('erp_net'), (int) $sum('erp_invoices'), [
            'imported' => $sum('imported_net'), 'corrected' => $sum('system_net'), 'documents' => (int) $sum('system_documents'), 'voided' => (int) $sum('voided_documents'),
        ]);
        $totals['voided_documents'] += $voidedWithoutSale;

        return [
            'company' => ['id' => $companyId, 'name' => (string) DB::table('companies')->where('id', $companyId)->value('name')],
            'period_from' => $from->toDateString(),
            'cutoff' => $cutoff->toDateString(),
            'totals' => $totals,
            'voided_without_sale' => $voidedWithoutSale,
            'rows' => $rows,
        ];
    }

    /**
     * @param  array{name?: string, imported: float, corrected: float, documents: int, voided: int}|null  $s
     * @return array<string, mixed>
     */
    private function row(string $seller, float $erpNet, int $erpInvoices, ?array $s): array
    {
        $system = round($s['corrected'] ?? 0.0, 2);
        $difference = round($erpNet - $system, 2);

        return [
            'seller' => $seller,
            'erp_net' => round($erpNet, 2),
            'system_net' => $system,
            'imported_net' => round($s['imported'] ?? 0.0, 2),
            'difference' => $difference,
            'difference_pct' => $erpNet > 0 ? round($difference / $erpNet * 100, 2) : null,
            'erp_invoices' => $erpInvoices,
            'system_documents' => $s['documents'] ?? 0,
            'voided_documents' => $s['voided'] ?? 0,
            'status' => $this->status($difference, $erpNet),
        ];
    }

    private function status(float $difference, float $erpNet): string
    {
        $abs = abs($difference);
        if ($abs <= (float) config('reports.origin_match_tolerance')) {
            return self::OK;
        }

        return $erpNet > 0 && $abs / $erpNet * 100 <= (float) config('reports.origin_near_pct') ? self::NEAR : self::DIFFERS;
    }

    /** @return array<string, array{name: string, imported: float, corrected: float, documents: int, voided: int}> por nombre normalizado del vendedor */
    private function system(int $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('sales_lines as l')
            ->join('sales_documents as d', 'd.id', '=', 'l.sales_document_id')
            ->join('sellers as s', 's.id', '=', 'd.seller_id')
            ->leftJoin('sales_line_corrections as cx', 'cx.sales_line_id', '=', 'l.id')
            ->leftJoin('sales_document_voids as vd', 'vd.sales_document_id', '=', 'd.id')
            ->where('l.company_id', $companyId)
            ->where('l.issued_on', '>=', $from->toDateString())->where('l.issued_on', '<', $to->addDay()->toDateString())
            ->groupBy('s.normalized_name')
            ->selectRaw('s.normalized_name as normalized, max(s.name) as name, sum(l.net_total) as imported')
            ->selectRaw('sum(case when vd.id is null then coalesce(cx.corrected_net, l.net_total) else 0 end) as corrected')
            ->selectRaw('count(distinct case when vd.id is null then d.id end) as documents, count(distinct case when vd.id is not null then d.id end) as voided')
            ->get();

        $out = [];
        foreach ($rows as $r) {
            $out[Seller::normalize((string) $r->normalized)] = [
                'name' => (string) $r->name, 'imported' => (float) $r->imported, 'corrected' => (float) $r->corrected, 'documents' => (int) $r->documents, 'voided' => (int) $r->voided,
            ];
        }

        return $out;
    }

    /** Facturas anuladas que el detalle de facturación marcó y de las que no hay venta cargada (el archivo de ventas las deja en cero). */
    private function voidedWithoutSale(int $companyId, CarbonImmutable $from, CarbonImmutable $to): int
    {
        return (int) DB::table('sales_document_voids as v')
            ->join('billing_detail_lines as b', fn ($j) => $j->on('b.company_id', '=', 'v.company_id')->on('b.document', '=', 'v.document'))
            ->where('v.company_id', $companyId)->whereNull('v.sales_document_id')
            ->where('b.issued_on', '>=', $from->toDateString())->where('b.issued_on', '<', $to->addDay()->toDateString())
            ->distinct()->count('v.document');
    }
}
