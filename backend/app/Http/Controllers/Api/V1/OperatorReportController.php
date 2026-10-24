<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportDetailRequest;
use App\Models\Operator;
use App\Services\Reports\DocumentListing;
use App\Services\Reports\Operators\OperatorDistribution;
use App\Services\Reports\Operators\OperatorReport;
use App\Services\Reports\ReportAudit;
use App\Services\Reports\ReportScope;
use Illuminate\Http\JsonResponse;

/** Reporte de evaluación de operadores (quienes facturan). El alcance es por compañía (ver ReportScope). */
class OperatorReportController extends Controller
{
    public function __construct(private readonly ReportScope $scope, private readonly ReportAudit $audit) {}

    /**
     * @response array{data: array{period: array{from: string, to: string, days: int}, comparison: array{mode: string, from: string, to: string, days: int, uneven: bool}, rows: list<array{operator: array{id: int, name: string, company_id: int, company: string|null, is_active: bool}, rank: int|null, sales: array{net_total: float, documents: int, customers: int, average_ticket: float|null, discount_pct: float|null, below_list_pct: float|null, categories_per_customer: float|null, top3_concentration_pct: float|null, previous_net_total: float, growth_pct: float|null, previous_documents: int, documents_growth_pct: float|null, share_pct: float|null}, production: array{lines: int, lines_per_document: float|null, active_days: int, documents_per_active_day: float|null, timed_documents_pct: float|null}, control: array{high_discount_lines: int, high_discount_lines_pct: float|null, zero_price_lines: int, zero_price_lines_pct: float|null, atypical_documents: int, atypical_documents_pct: float|null, negative_documents: int, direct_sales_pct: float|null}, semaphores: array<string, string>}>, totals: array{operators: int, net_total: float, previous_net_total: float, growth_pct: float|null, documents: int, lines: int, average_ticket: float|null, high_discount_lines: int, zero_price_lines: int, atypical_documents: int, negative_documents: int, red_flags: int}, reconciliation: array{consolidated_net: float, rows_net: float, net_difference: float, consolidated_documents: int, rows_documents: int, documents_difference: int, matches: bool}, notes: list<string>}}
     */
    public function index(ReportDetailRequest $request, OperatorReport $report): JsonResponse
    {
        $this->audit->viewed($request->user(), 'operators', $request->auditFilters());

        return response()->json(['data' => $report->build($this->scope->companyIds($request->user(), $request->companyId()), $request->period(), $request->salesFilters())]);
    }

    /**
     * @response array{data: array{subject: array{id: int, name: string}, rows: list<array{id: int, document: string, issued_on: string, customer_code: string, customer_name: string, branch: string, operator: string, seller: string, lines: int, net_total: float, tax_total: float, grand_total: float}>, totals: array{documents: int, net_total: float, tax_total: float, grand_total: float}, page: int, per_page: int, last_page: int}}
     */
    public function documents(ReportDetailRequest $request, Operator $operator, DocumentListing $listing): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), $operator->company_id), 404);
        $this->audit->viewed($request->user(), 'operator_documents', ['operator_id' => $operator->id, 'company_id' => $operator->company_id] + $request->auditFilters());

        return response()->json(['data' => $listing->documents($this->scope->companyIds($request->user(), $operator->company_id), 'operator', $operator->id, $operator->name, $request->from(), $request->to(), $request->salesFilters(), $request->page())]);
    }

    /**
     * @response array{data: array{operator: array{id: int, name: string}, sellers: list<array{id: int, name: string, net_total: float, share_pct: float|null, direct: bool}>, sellers_count: int, branches: list<array{id: int, name: string, net_total: float, share_pct: float|null}>, documents: int, timed_documents: int, by_hour: list<int>|null, by_weekday: list<int>, daily: list<array{date: string, documents: int}>}}
     */
    public function distribution(ReportDetailRequest $request, Operator $operator, OperatorDistribution $distribution): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), $operator->company_id), 404);
        $this->audit->viewed($request->user(), 'operator_distribution', ['operator_id' => $operator->id, 'company_id' => $operator->company_id] + $request->auditFilters());

        return response()->json(['data' => $distribution->for($this->scope->companyIds($request->user(), $operator->company_id), $operator, $request->from(), $request->to(), $request->salesFilters())]);
    }
}
