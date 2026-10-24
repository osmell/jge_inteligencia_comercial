<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Services\Sales\SalesQuery;

/**
 * Contraste: la venta consolidada (sin agrupar) contra la suma de las filas de un reporte. Deben coincidir; si no, hay ventas
 * de evaluados que el reporte no muestra.
 */
class Reconciliation
{
    public function __construct(private readonly SalesQuery $query) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array{consolidated_net: float, rows_net: float, net_difference: float, consolidated_documents: int, rows_documents: int, documents_difference: int, matches: bool}
     */
    public function between(?array $companyIds, ReportPeriod $period, array $filters, float $rowsNet, int $rowsDocuments): array
    {
        $all = $this->query->baseForCompanies($period->from, $period->to, $filters, $companyIds)
            ->selectRaw('coalesce(sum(sales_lines.net_total), 0) as net, count(distinct sales_lines.sales_document_id) as documents')
            ->toBase()->first();

        $net = round((float) ($all->net ?? 0), 2);
        $documents = (int) ($all->documents ?? 0);
        $rowsNet = round($rowsNet, 2);

        return [
            'consolidated_net' => $net, 'rows_net' => $rowsNet, 'net_difference' => round($net - $rowsNet, 2),
            'consolidated_documents' => $documents, 'rows_documents' => $rowsDocuments, 'documents_difference' => $documents - $rowsDocuments,
            'matches' => abs($net - $rowsNet) < 0.01 && $documents === $rowsDocuments,
        ];
    }
}
