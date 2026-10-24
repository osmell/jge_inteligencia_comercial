<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportDetailRequest;
use App\Models\Branch;
use App\Services\Reports\Branches\BranchCategoryMix;
use App\Services\Reports\Branches\BranchReport;
use App\Services\Reports\DocumentListing;
use App\Services\Reports\ReportAudit;
use App\Services\Reports\ReportScope;
use Illuminate\Http\JsonResponse;

/** Reporte de evaluación de sucursales. Cada persona ve las sucursales que tiene asignadas (ver BranchReport). */
class BranchReportController extends Controller
{
    public function __construct(private readonly ReportScope $scope, private readonly ReportAudit $audit) {}

    /**
     * @response array{data: array{period: array{from: string, to: string, days: int}, comparison: array{mode: string, from: string, to: string, days: int, uneven: bool}, rows: list<array{branch: array{id: int, name: string, code: string, company_id: int, company: string, is_active: bool}, rank: int|null, sales: array{net_total: float, documents: int, customers: int, average_ticket: float|null, discount_pct: float|null, below_list_pct: float|null, categories_per_customer: float|null, top3_concentration_pct: float|null, previous_net_total: float, growth_pct: float|null, previous_documents: int, documents_growth_pct: float|null, share_pct: float|null}, dependency: array{sellers_count: int, top_seller: string|null, top_seller_pct: float|null}, portfolio: array{total: int, active: int, new: int, recovered: int, inactive: int, lost: int, lost_pct: float|null}, credit: array{balance: float, overdue: float, overdue_pct: float|null}, inventory: array{products_in_stock: int, no_rotation: int, no_rotation_pct: float|null, sold_products: int, stockout_with_demand: int, stockout_demand_pct: float|null, open_shortfalls: int}, semaphores: array<string, string>}>, totals: array{branches: int, net_total: float, previous_net_total: float, growth_pct: float|null, documents: int, average_ticket: float|null, credit_balance: float, credit_overdue: float, no_rotation: int, stockout_with_demand: int, open_shortfalls: int, red_flags: int}, reconciliation: array{consolidated_net: float, rows_net: float, net_difference: float, consolidated_documents: int, rows_documents: int, documents_difference: int, matches: bool}, notes: list<string>}}
     */
    public function index(ReportDetailRequest $request, BranchReport $report): JsonResponse
    {
        $this->audit->viewed($request->user(), 'branches', $request->auditFilters());

        return response()->json(['data' => $report->build($this->scope->companyIds($request->user(), $request->companyId()), $request->period(), $request->salesFilters())]);
    }

    /**
     * @response array{data: array{subject: array{id: int, name: string}, rows: list<array{id: int, document: string, issued_on: string, customer_code: string, customer_name: string, branch: string, operator: string, seller: string, lines: int, net_total: float, tax_total: float, grand_total: float}>, totals: array{documents: int, net_total: float, tax_total: float, grand_total: float}, page: int, per_page: int, last_page: int}}
     */
    public function documents(ReportDetailRequest $request, Branch $branch, DocumentListing $listing): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), $branch->company_id), 404);
        $this->audit->viewed($request->user(), 'branch_documents', ['branch_id' => $branch->id, 'company_id' => $branch->company_id] + $request->auditFilters());

        return response()->json(['data' => $listing->documents($this->scope->companyIds($request->user(), $branch->company_id), 'branch', $branch->id, $branch->name, $request->from(), $request->to(), ['branch_id' => $branch->id], $request->page())]);
    }

    /**
     * @response array{data: array{branches: list<array{id: int, name: string}>, categories: list<array{name: string, net_total: float, share_pct: float, cells: list<array{branch_id: int, net_total: float, share_pct: float, index: int|null}>}>, shown: int, total_categories: int}}
     */
    public function mix(ReportDetailRequest $request, BranchCategoryMix $mix, BranchReport $report): JsonResponse
    {
        $this->audit->viewed($request->user(), 'branch_mix', $request->auditFilters());
        $companyIds = $this->scope->companyIds($request->user(), $request->companyId());

        return response()->json(['data' => $mix->build($companyIds, $request->from(), $request->to(), ['branch_ids' => $report->visibleBranchIds($companyIds, $request->salesFilters())])]);
    }
}
