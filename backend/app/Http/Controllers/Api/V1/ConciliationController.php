<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ConciliationIssuesRequest;
use App\Http\Requests\ReportDetailRequest;
use App\Services\Reports\Conciliation\ConciliationReport;
use App\Services\Reports\Conciliation\CreditConciliation;
use App\Services\Reports\Conciliation\SalesIntegrityChecks;
use App\Services\Reports\ReportAudit;
use App\Services\Reports\ReportScope;
use Illuminate\Http\JsonResponse;

/** Conciliación de las cifras consolidadas con sus partes y con otras fuentes. El alcance es por compañía (ver ReportScope). */
class ConciliationController extends Controller
{
    public function __construct(private readonly ReportScope $scope, private readonly ReportAudit $audit) {}

    /**
     * @response array{data: array{period: array{from: string, to: string, days: int}, consolidated: array{net_total: float, tax_total: float, grand_total: float, documents: int, lines: int}, breakdowns: list<array{dimension: string, rows: int, net_total: float, documents: int, net_difference: float, documents_difference: int, matches: bool}>, integrity: list<array{code: string, count: int, ok: bool}>, imports: array{batches: list<array{id: int, name: string, finished_at: string|null, date_from: string|null, date_to: string|null, declared_documents: int, registered_documents: int, declared_net: float, registered_net: float, net_comparable: bool, duplicates: int, matches: bool}>, matched: int, differing: int, not_comparable: int, out_of_scope: int}, credit: array{linked: int, mismatched: int, unlinked: int}, problems: int}}
     */
    public function index(ReportDetailRequest $request, ConciliationReport $report): JsonResponse
    {
        $this->audit->viewed($request->user(), 'conciliation', $request->auditFilters());

        return response()->json(['data' => $report->build($this->scope->companyIds($request->user(), $request->companyId()), $request->from(), $request->to(), $request->salesFilters())]);
    }

    /**
     * @response array{data: array{rows: list<array{id: int, document: string, issued_on: string, customer_name: string, branch: string, detail: string}>, total: int, page: int, per_page: int, last_page: int}}
     */
    public function issues(ConciliationIssuesRequest $request, SalesIntegrityChecks $integrity, CreditConciliation $credit): JsonResponse
    {
        $this->audit->viewed($request->user(), 'conciliation_issues', $request->auditFilters());
        $companyIds = $this->scope->companyIds($request->user(), $request->companyId());

        $data = $request->code() === CreditConciliation::CODE
            ? $credit->issues($companyIds, $request->from(), $request->to(), $request->page())
            : $integrity->issues($request->code(), $companyIds, $request->from(), $request->to(), $request->salesFilters(), $request->page());

        return response()->json(['data' => $data]);
    }
}
