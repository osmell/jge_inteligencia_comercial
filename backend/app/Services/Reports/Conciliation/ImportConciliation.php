<?php

declare(strict_types=1);

namespace App\Services\Reports\Conciliation;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Lo que declaraba cada archivo de ventas importado contra lo que hoy hay guardado de ese lote: documentos nuevos y venta neta.
 * La venta neta del archivo incluye los documentos que ya estaban cargados (duplicados idénticos), así que solo se compara cuando el lote
 * no tuvo ninguno; con duplicados se compara únicamente la cantidad de documentos nuevos.
 * Un lote sin venta neta declarada se trata igual.
 * Solo se evalúan los lotes cuyas compañías están todas dentro del alcance de la persona.
 */
class ImportConciliation
{
    /**
     * @param  list<int>|null  $companyIds
     * @return array{batches: list<array<string, mixed>>, matched: int, differing: int, not_comparable: int, out_of_scope: int}
     */
    public function build(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $tolerance = (float) config('imports.amount_tolerance');
        $batches = ImportBatch::query()->where('type', 'sales')->where('status', ImportStatus::Imported->value)->orderByDesc('id')->get();

        $rows = [];
        $outOfScope = 0;
        foreach ($batches as $batch) {
            $summary = (array) $batch->summary;
            if (! $this->overlaps($summary, $from, $to)) {
                continue;
            }
            $batchCompanies = array_map(fn ($c) => (int) $c['id'], (array) ($summary['companies'] ?? []));
            if ($companyIds !== null && array_diff($batchCompanies, $companyIds) !== []) {
                $outOfScope++;

                continue;
            }

            $registered = DB::table('sales_documents as sd')->where('sd.import_batch_id', $batch->id)
                ->leftJoin('sales_lines as sl', 'sl.sales_document_id', '=', 'sd.id')
                ->selectRaw('count(distinct sd.id) as documents, coalesce(sum(sl.net_total), 0) as net')->first();
            $declaredNet = (float) ($summary['totals']['net_total'] ?? 0);
            $registeredNet = round((float) ($registered->net ?? 0), 2);
            $registeredDocuments = (int) ($registered->documents ?? 0);

            // Sin venta neta declarada en el lote (datos cargados de otra forma, como los de demostración) tampoco hay con qué comparar.
            $netComparable = $batch->documents_duplicate === 0 && isset($summary['totals']['net_total']);
            $documentsMatch = $registeredDocuments === $batch->documents_new;
            $netMatches = ! $netComparable || abs($declaredNet - $registeredNet) <= $tolerance;

            $rows[] = [
                'id' => $batch->id,
                'name' => $batch->original_name,
                'finished_at' => $batch->finished_at === null ? null : CarbonImmutable::parse((string) $batch->finished_at)->toDateTimeString(),
                'date_from' => $summary['date_from'] ?? null,
                'date_to' => $summary['date_to'] ?? null,
                'declared_documents' => $batch->documents_new,
                'registered_documents' => $registeredDocuments,
                'declared_net' => round($declaredNet, 2),
                'registered_net' => $registeredNet,
                'net_comparable' => $netComparable,
                'duplicates' => $batch->documents_duplicate,
                'matches' => $documentsMatch && $netMatches,
            ];
        }

        return [
            'batches' => $rows,
            'matched' => count(array_filter($rows, fn (array $r) => $r['matches'])),
            'differing' => count(array_filter($rows, fn (array $r) => ! $r['matches'])),
            'not_comparable' => count(array_filter($rows, fn (array $r) => ! $r['net_comparable'])),
            'out_of_scope' => $outOfScope,
        ];
    }

    /** @param  array<string, mixed>  $summary */
    private function overlaps(array $summary, CarbonImmutable $from, CarbonImmutable $to): bool
    {
        if (empty($summary['date_from']) || empty($summary['date_to'])) {
            return true;
        }

        return substr((string) $summary['date_from'], 0, 10) <= $to->toDateString() && substr((string) $summary['date_to'], 0, 10) >= $from->toDateString();
    }
}
