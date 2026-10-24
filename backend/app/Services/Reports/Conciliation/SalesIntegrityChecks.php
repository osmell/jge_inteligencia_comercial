<?php

declare(strict_types=1);

namespace App\Services\Reports\Conciliation;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Comprobaciones de integridad de las ventas guardadas: que el encabezado de cada documento cuadre con sus líneas. Cada una devuelve
 * cuántos documentos tienen el problema y, para investigarlo, cuáles son.
 */
class SalesIntegrityChecks
{
    public const PER_PAGE = 50;

    /** Columnas que describen un documento en el listado de problemas. */
    private const COLUMNS = 'sd.id, sd.series, sd.number, sd.issued_on, sd.customer_name, b.name as branch';

    public const CODES = ['lines_count_mismatch', 'documents_without_lines', 'line_company_mismatch', 'line_branch_mismatch', 'line_date_mismatch', 'line_total_mismatch'];

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<string, int> código → documentos con el problema
     */
    public function counts(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $out = [];
        foreach (self::CODES as $code) {
            $out[$code] = DB::query()->fromSub($this->documents($code, $companyIds, $from, $to, $filters), 'issues')->count();
        }

        return $out;
    }

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function issues(string $code, ?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters, int $page): array
    {
        $query = $this->documents($code, $companyIds, $from, $to, $filters);
        $total = DB::query()->fromSub($query, 'issues')->count();
        $rows = DB::query()->fromSub($query, 'issues')->orderByDesc('issued_on')->orderByDesc('id')->offset(($page - 1) * self::PER_PAGE)->limit(self::PER_PAGE)->get();

        return [
            'rows' => array_values($rows->map(fn ($r) => [
                'id' => (int) $r->id, 'document' => $r->series.'-'.$r->number, 'issued_on' => substr((string) $r->issued_on, 0, 10),
                'customer_name' => $r->customer_name, 'branch' => $r->branch, 'detail' => $this->detail($code, $r),
            ])->all()),
            'total' => $total, 'page' => $page, 'per_page' => self::PER_PAGE, 'last_page' => max(1, (int) ceil($total / self::PER_PAGE)),
        ];
    }

    /**
     * Un registro por documento afectado, con lo necesario para describir el problema.
     *
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     */
    private function documents(string $code, ?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters): Builder
    {
        $docs = DB::table('sales_documents as sd')
            ->join('branches as b', 'b.id', '=', 'sd.branch_id')
            ->where('sd.issued_on', '>=', $from->toDateString())->where('sd.issued_on', '<', $to->addDay()->toDateString())
            ->when($companyIds !== null, fn ($q) => $q->whereIn('sd.company_id', (array) $companyIds))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('sd.branch_id', (int) $filters['branch_id']));
        $group = ['sd.id', 'sd.series', 'sd.number', 'sd.issued_on', 'sd.customer_name', 'b.name'];

        return match ($code) {
            'lines_count_mismatch' => $docs->leftJoin('sales_lines as sl', 'sl.sales_document_id', '=', 'sd.id')
                ->selectRaw(self::COLUMNS.', sd.lines_count as declared, count(sl.id) as actual')->groupBy([...$group, 'sd.lines_count'])
                ->havingRaw('count(sl.id) > 0 and count(sl.id) <> sd.lines_count'),
            'documents_without_lines' => $docs->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales_lines as sl')->whereColumn('sl.sales_document_id', 'sd.id'))
                ->selectRaw(self::COLUMNS.', sd.lines_count as declared, 0 as actual'),
            'line_company_mismatch' => $this->lineProblem($docs, $group, 'sl.company_id <> sd.company_id'),
            'line_branch_mismatch' => $this->lineProblem($docs, $group, 'sl.branch_id <> sd.branch_id'),
            'line_date_mismatch' => $this->lineProblem($docs, $group, 'sl.issued_on <> sd.issued_on'),
            // Diferencia máxima aceptada entre el neto más el impuesto y el total de una línea: 0.01 (redondeo).
            'line_total_mismatch' => $this->lineProblem($docs, $group, 'abs(sl.net_total + sl.tax_total - sl.grand_total) > 0.01'),
            default => throw new \InvalidArgumentException("Comprobación desconocida: $code"),
        };
    }

    /**
     * @param  list<string>  $group
     * @param  literal-string  $condition
     */
    private function lineProblem(Builder $docs, array $group, string $condition): Builder
    {
        return $docs->join('sales_lines as sl', 'sl.sales_document_id', '=', 'sd.id')->whereRaw($condition)
            ->selectRaw(self::COLUMNS.', 0 as declared, count(sl.id) as actual')->groupBy($group);
    }

    private function detail(string $code, \stdClass $row): string
    {
        $lines = (int) $row->actual;

        return match ($code) {
            'lines_count_mismatch' => "El documento declara {$row->declared} líneas y tiene {$lines}.",
            'documents_without_lines' => 'El documento no tiene ninguna línea.',
            'line_company_mismatch' => "{$lines} línea(s) pertenecen a otra compañía que el documento.",
            'line_branch_mismatch' => "{$lines} línea(s) pertenecen a otra sucursal que el documento.",
            'line_date_mismatch' => "{$lines} línea(s) tienen una fecha distinta a la del documento.",
            'line_total_mismatch' => "{$lines} línea(s) donde neto + impuesto no suman su total.",
            default => '',
        };
    }
}
