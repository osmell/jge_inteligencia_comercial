<?php

declare(strict_types=1);

namespace App\Services\Reports\Conciliation;

use App\Services\Sales\SalesBasis;
use App\Services\Sales\SalesQuery;
use Carbon\CarbonImmutable;

/**
 * Conciliación: contrasta la venta consolidada con sus partes y con otras fuentes.
 *  1. Sumas por compañía, sucursal, vendedor y operador contra el consolidado (deben dar lo mismo).
 *  2. Integridad: que cada documento cuadre con sus líneas.
 *  3. Importaciones: lo que declaraba cada archivo contra lo guardado.
 *  4. Crédito: las facturas de crédito contra los documentos de venta a los que se enlazaron.
 * El detalle de facturación de cada vendedor, operador y sucursal se abre desde los reportes de evaluación.
 */
class ConciliationReport
{
    /** Columna por la que se agrupa cada desglose (lista blanca). */
    private const BREAKDOWNS = ['company' => 'sales_lines.company_id', 'branch' => 'sales_lines.branch_id', 'seller' => 'sd.seller_id', 'operator' => 'sd.operator_id'];

    public function __construct(
        private readonly SalesQuery $query,
        private readonly SalesIntegrityChecks $integrity,
        private readonly ImportConciliation $imports,
        private readonly CreditConciliation $credit,
    ) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $all = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->selectRaw('coalesce(sum('.SalesBasis::net('sales_lines').'), 0) as net, coalesce(sum('.SalesBasis::tax('sales_lines').'), 0) as tax, coalesce(sum('.SalesBasis::grand('sales_lines').'), 0) as grand, count(distinct sales_lines.sales_document_id) as documents, count(*) as lines')
            ->toBase()->first();
        $consolidated = [
            'net_total' => round((float) ($all->net ?? 0), 2), 'tax_total' => round((float) ($all->tax ?? 0), 2), 'grand_total' => round((float) ($all->grand ?? 0), 2),
            'documents' => (int) ($all->documents ?? 0), 'lines' => (int) ($all->lines ?? 0),
        ];

        $breakdowns = [];
        foreach (self::BREAKDOWNS as $dimension => $column) {
            $rows = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
                ->selectRaw("{$column} as id, sum(".SalesBasis::net('sales_lines').') as net, count(distinct sales_lines.sales_document_id) as documents')
                ->groupByRaw($column)->toBase()->get();
            $net = round((float) $rows->sum('net'), 2);
            $documents = (int) $rows->sum('documents');
            $breakdowns[] = [
                'dimension' => $dimension, 'rows' => $rows->count(), 'net_total' => $net, 'documents' => $documents,
                'net_difference' => round($consolidated['net_total'] - $net, 2), 'documents_difference' => $consolidated['documents'] - $documents,
                'matches' => abs($consolidated['net_total'] - $net) < 0.01 && $consolidated['documents'] === $documents,
            ];
        }

        $integrity = [];
        foreach ($this->integrity->counts($companyIds, $from, $to, $filters) as $code => $count) {
            $integrity[] = ['code' => $code, 'count' => $count, 'ok' => $count === 0];
        }
        $credit = $this->credit->counts($companyIds, $from, $to);
        $imports = $this->imports->build($companyIds, $from, $to);

        $problems = count(array_filter($breakdowns, fn (array $b) => ! $b['matches'])) + count(array_filter($integrity, fn (array $i) => ! $i['ok']))
            + ($credit['mismatched'] > 0 ? 1 : 0) + ($imports['differing'] > 0 ? 1 : 0);

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'days' => (int) $from->diffInDays($to) + 1],
            'consolidated' => $consolidated,
            'breakdowns' => $breakdowns,
            'integrity' => $integrity,
            'imports' => $imports,
            'credit' => $credit,
            'problems' => $problems,
        ];
    }
}
