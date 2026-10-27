<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Illuminate\Support\Facades\DB;

/**
 * Detecta las facturas anuladas a partir del detalle de facturación y las enlaza con sus ventas (docs/30_CONCILIACION_FACTURACION.md).
 *
 * El reporte del detalle repite cada factura anulada con la serie «FA»; en agosto de 2026 las 188 facturas con esa serie son exactamente
 * las 188 que el archivo de ventas deja en cero. Es idempotente y no modifica las ventas.
 */
final class VoidDetection
{
    public const VOID_SERIES = 'FA';

    /** @return array{new: int, with_sales: int} anuladas nuevas y, entre todas las de la compañía, las que ya tienen venta cargada */
    public function run(int $batchId, ?int $companyId = null): array
    {
        $found = DB::table('billing_detail_lines as b')
            ->where('b.series', self::VOID_SERIES)
            ->when($companyId, fn ($q) => $q->where('b.company_id', $companyId))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales_document_voids as v')->whereColumn('v.company_id', 'b.company_id')->whereColumn('v.document', 'b.document'))
            ->select(['b.company_id', 'b.document', DB::raw($batchId), DB::raw('current_timestamp'), DB::raw('current_timestamp')])
            ->distinct();

        $new = DB::table('sales_document_voids')->insertUsing(['company_id', 'document', 'import_batch_id', 'created_at', 'updated_at'], $found);

        return ['new' => $new, 'with_sales' => $this->linkSales($companyId)];
    }

    /** Enlaza las anuladas con la venta del mismo número (si existe) y devuelve cuántas anuladas tienen venta cargada. */
    public function linkSales(?int $companyId = null): int
    {
        $pending = DB::table('sales_document_voids as v')->whereNull('v.sales_document_id')
            ->when($companyId, fn ($q) => $q->where('v.company_id', $companyId))
            ->join('sales_documents as d', fn ($j) => $j->on('d.company_id', '=', 'v.company_id')->on('d.number', '=', 'v.document'))
            ->select(['v.id', DB::raw('min(d.id) as sales_document_id')])->groupBy('v.id')->get();

        foreach ($pending as $row) {
            DB::table('sales_document_voids')->where('id', $row->id)->update(['sales_document_id' => $row->sales_document_id]);
        }

        return DB::table('sales_document_voids')->whereNotNull('sales_document_id')->when($companyId, fn ($q) => $q->where('company_id', $companyId))->count();
    }

    /**
     * @param  array<array-key, array{0: int, 1: string}>  $documents  pares [compañía, número]
     * @return array<string, true> claves «compañía|número» de las que figuran como anuladas
     */
    public function voidedAmong(array $documents): array
    {
        $byCompany = [];
        foreach ($documents as [$companyId, $number]) {
            $byCompany[$companyId][] = $number;
        }

        $found = [];
        foreach ($byCompany as $companyId => $numbers) {
            foreach (array_chunk(array_unique($numbers), 500) as $chunk) {
                foreach (DB::table('sales_document_voids')->where('company_id', $companyId)->whereIn('document', $chunk)->pluck('document') as $document) {
                    $found["{$companyId}|{$document}"] = true;
                }
            }
        }

        return $found;
    }
}
