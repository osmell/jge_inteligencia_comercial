<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\SellerReportRequest;
use App\Models\Seller;
use App\Services\Reports\ReportAudit;
use App\Services\Reports\ReportScope;
use App\Services\Reports\Sellers\SellerBilling;
use App\Services\Reports\Sellers\SellerReport;
use Illuminate\Http\JsonResponse;

/** Reporte de evaluación de vendedores. El alcance es por compañía (ver ReportScope). */
class SellerReportController extends Controller
{
    public function __construct(private readonly ReportScope $scope, private readonly ReportAudit $audit) {}

    /**
     * @response array{data: array{period: array{from: string, to: string, days: int}, comparison: array{mode: string, from: string, to: string, days: int, uneven: bool}, rows: list<array{seller: array{id: int, name: string, company_id: int, company: string|null, branch_id: int|null, branch: string|null, is_active: bool}, rank: int|null, sales: array{net_total: float, documents: int, customers: int, average_ticket: float|null, discount_pct: float|null, below_list_pct: float|null, categories_per_customer: float|null, top3_concentration_pct: float|null, previous_net_total: float, growth_pct: float|null, share_pct: float|null}, goal: array{goal: float|null, attainment_pct: float|null, projected_pct: float|null, status: string}, portfolio: array{total: int, active: int, new: int, recovered: int, inactive: int, lost: int, lost_pct: float|null}, credit: array{balance: float, overdue: float, overdue_pct: float|null}, follow_up: array{quotes_issued: int, quotes_converted: int, quote_conversion_pct: float|null, quotes_amount: float, has_user: bool, contacts: int|null, actions_due: int|null, actions_on_time_pct: float|null, overdue_follow_ups: int|null}, semaphores: array<string, string>}>, totals: array{sellers: int, net_total: float, previous_net_total: float, growth_pct: float|null, documents: int, average_ticket: float|null, goal: float, attainment_pct: float|null, sellers_with_goal: int, credit_balance: float, credit_overdue: float, red_flags: int}, reconciliation: array{consolidated_net: float, sellers_net: float, net_difference: float, consolidated_documents: int, sellers_documents: int, documents_difference: int, matches: bool}, notes: list<string>}}
     */
    public function index(SellerReportRequest $request, SellerReport $report): JsonResponse
    {
        $this->audit->viewed($request->user(), 'sellers', $request->auditFilters());

        return response()->json(['data' => $report->build($this->scope->companyIds($request->user(), $request->companyId()), $request->period(), $request->salesFilters())]);
    }

    /**
     * @response array{data: array{seller: array{id: int, name: string}, rows: list<array{id: int, document: string, issued_on: string, customer_code: string, customer_name: string, branch: string, operator: string, lines: int, net_total: float, tax_total: float, grand_total: float}>, totals: array{documents: int, net_total: float, tax_total: float, grand_total: float}, page: int, per_page: int, last_page: int}}
     */
    public function documents(SellerReportRequest $request, Seller $seller, SellerBilling $billing): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), $seller->company_id), 404);
        $this->audit->viewed($request->user(), 'seller_documents', ['seller_id' => $seller->id, 'company_id' => $seller->company_id] + $request->auditFilters());

        return response()->json(['data' => $billing->documents($this->scope->companyIds($request->user(), $seller->company_id), $seller, $request->from(), $request->to(), $request->salesFilters(), $request->page())]);
    }
}
