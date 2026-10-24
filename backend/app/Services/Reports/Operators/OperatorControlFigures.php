<?php

declare(strict_types=1);

namespace App\Services\Reports\Operators;

use App\Services\Sales\SalesQuery;
use Carbon\CarbonImmutable;

/**
 * Producción y control de captura de cada operador (quien factura). Solo usa lo que traen los archivos de venta; el sistema no
 * sabe qué operador anuló o corrigió un documento.
 *
 *  - descuento alto: líneas con descuento ≥ `reports.high_discount_line_pct` % de su subtotal;
 *  - sin precio: líneas con cantidad cuyo precio unitario o venta neta es cero (mercancía regalada o error de captura);
 *  - atípicos: documentos con venta neta por encima de la cota de valores extremos de su compañía (ver `config/reports.php`);
 *  - negativos: documentos con venta neta menor que cero;
 *  - venta directa: venta cuyo vendedor se llama igual que el operador (el facturador vendió y facturó).
 */
class OperatorControlFigures
{
    public function __construct(private readonly SalesQuery $query) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>> operador → cifras
     */
    public function forPeriod(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $lines = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->join('sellers as ctl_s', 'ctl_s.id', '=', 'sd.seller_id')
            ->join('operators as ctl_o', 'ctl_o.id', '=', 'sd.operator_id')
            ->selectRaw('sd.operator_id as operator_id')
            ->selectRaw('count(*) as lines')
            ->selectRaw('count(distinct sales_lines.issued_on) as active_days')
            ->selectRaw('count(distinct case when sd.issued_at is not null then sales_lines.sales_document_id end) as timed_documents')
            ->selectRaw('sum(case when sales_lines.subtotal > 0 and coalesce(sales_lines.discount_total, 0) >= sales_lines.subtotal * ? / 100 then 1 else 0 end) as high_discount_lines', [(float) config('reports.high_discount_line_pct')])
            ->selectRaw('sum(case when sales_lines.units > 0 and (sales_lines.unit_price = 0 or sales_lines.net_total = 0) then 1 else 0 end) as zero_price_lines')
            ->selectRaw('coalesce(sum(case when ctl_s.normalized_name = ctl_o.normalized_name then sales_lines.net_total end), 0) as direct_net')
            ->groupBy('sd.operator_id')
            ->toBase()->get();

        $documents = $this->documentFlags($companyIds, $from, $to, $filters);

        $out = [];
        foreach ($lines as $r) {
            $id = (int) $r->operator_id;
            $out[$id] = [
                'lines' => (int) $r->lines,
                'active_days' => (int) $r->active_days,
                'timed_documents' => (int) $r->timed_documents,
                'high_discount_lines' => (int) $r->high_discount_lines,
                'zero_price_lines' => (int) $r->zero_price_lines,
                'direct_net' => round((float) $r->direct_net, 2),
                'atypical_documents' => $documents[$id]['atypical'] ?? 0,
                'negative_documents' => $documents[$id]['negative'] ?? 0,
                'atypical_evaluated' => $documents[$id]['evaluated'] ?? false,
            ];
        }

        return $out;
    }

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<int, array{atypical: int, negative: int, evaluated: bool}>
     */
    private function documentFlags(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $documents = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->selectRaw('sd.id as document_id, sd.company_id as company_id, sd.operator_id as operator_id, sum(sales_lines.net_total) as net')
            ->groupBy('sd.id', 'sd.company_id', 'sd.operator_id')
            ->toBase()->get();

        $fences = [];
        foreach ($documents->groupBy('company_id') as $companyId => $rows) {
            $fences[(int) $companyId] = $this->fence(array_values($rows->pluck('net')->map(fn ($v) => (float) $v)->sort()->all()));
        }

        $out = [];
        foreach ($documents as $d) {
            $net = (float) $d->net;
            $fence = $fences[(int) $d->company_id];
            $row = $out[(int) $d->operator_id] ?? ['atypical' => 0, 'negative' => 0, 'evaluated' => false];
            $row['evaluated'] = $row['evaluated'] || $fence !== null;
            $row['atypical'] += $fence !== null && $net > $fence ? 1 : 0;
            $row['negative'] += $net < 0 ? 1 : 0;
            $out[(int) $d->operator_id] = $row;
        }

        return $out;
    }

    /**
     * Cota de valores extremos (Q3 + factor × RIQ) de una lista ordenada; null si no hay documentos suficientes o no hay dispersión.
     *
     * @param  list<float>  $sorted
     */
    private function fence(array $sorted): ?float
    {
        if (count($sorted) < (int) config('reports.atypical_min_documents')) {
            return null;
        }
        $q1 = $this->quantile($sorted, 0.25);
        $q3 = $this->quantile($sorted, 0.75);

        return $q3 > $q1 ? $q3 + (float) config('reports.atypical_iqr_factor') * ($q3 - $q1) : null;
    }

    /** @param  list<float>  $sorted */
    private function quantile(array $sorted, float $q): float
    {
        $position = (count($sorted) - 1) * $q;
        $below = (int) floor($position);
        $above = (int) ceil($position);

        return $sorted[$below] + ($sorted[$above] - $sorted[$below]) * ($position - $below);
    }
}
