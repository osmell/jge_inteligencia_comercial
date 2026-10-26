<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductMixRequest;
use App\Models\Operator;
use App\Models\Seller;
use App\Services\Reports\Products\ProductMixDetail;
use App\Services\Reports\Products\ProductMixReport;
use App\Services\Reports\ReportAudit;
use App\Services\Reports\ReportScope;
use Illuminate\Http\JsonResponse;

/** Mezcla de productos: qué se factura por producto, categoría, línea o grupo y qué tan amplio es el catálogo que mueve cada vendedor u operador. */
class ProductMixReportController extends Controller
{
    public function __construct(private readonly ReportScope $scope, private readonly ReportAudit $audit) {}

    /**
     * @response array{data: array{period: array{from: string, to: string, days: int}, comparison: array{mode: string, from: string, to: string, days: int, uneven: bool}, subject: string, level: string, ranking: array{items: list<array{key: string, name: string, net_total: float, units: float, documents: int, customers: int, rank: int, share_pct: float|null, previous_net_total: float, growth_pct: float|null}>, total_items: int, truncated: bool, net_total: float}, rows: list<array{subject: array{id: int, name: string, company_id: int, is_active: bool}, rank: int|null, net_total: float, share_pct: float|null, documents: int, products_sold: int, categories_sold: int, categories_catalog: int, category_coverage_pct: float|null, lines_sold: int, lines_catalog: int, line_coverage_pct: float|null, groups_sold: int, top5_share_pct: float|null}>, totals: array{people: int, net_total: float, catalog_categories: int, catalog_lines: int, average_category_coverage_pct: float|null, below_half_coverage: int}, reconciliation: array{consolidated_net: float, rows_net: float, net_difference: float, consolidated_documents: int, rows_documents: int, documents_difference: int, matches: bool}, notes: list<string>}}
     */
    public function index(ProductMixRequest $request, ProductMixReport $report): JsonResponse
    {
        $this->audit->viewed($request->user(), 'products', $request->auditFilters());

        return response()->json(['data' => $report->build($this->scope->companyIds($request->user(), $request->companyId()), $request->period(), $request->salesFilters(), $request->subject(), $request->level())]);
    }

    /**
     * @response array{data: array{subject: array{id: int, name: string, company_id: int, kind: string}, level: string, summary: array{subject: array{id: int, name: string, company_id: int, is_active: bool}, rank: int|null, net_total: float, share_pct: float|null, documents: int, products_sold: int, categories_sold: int, categories_catalog: int, category_coverage_pct: float|null, lines_sold: int, lines_catalog: int, line_coverage_pct: float|null, groups_sold: int, top5_share_pct: float|null}|null, ranking: array{items: list<array{key: string, name: string, net_total: float, units: float, documents: int, customers: int, rank: int, share_pct: float|null, previous_net_total: float, growth_pct: float|null, team_share_pct: float|null, mix_index: float|null}>, total_items: int, truncated: bool, net_total: float}, gaps: array{categories: list<array{key: string, name: string, net_total: float, share_pct: float|null, customers: int}>, lines: list<array{key: string, name: string, net_total: float, share_pct: float|null, customers: int}>}, star_products: array{top: int, sold: int, missing: list<array{key: string, name: string, net_total: float, units: float, documents: int, customers: int}>}}}
     */
    public function seller(ProductMixRequest $request, Seller $seller, ProductMixDetail $detail): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), $seller->company_id), 404);
        $this->audit->viewed($request->user(), 'product_mix_seller', ['seller_id' => $seller->id, 'company_id' => $seller->company_id] + $request->auditFilters());

        return response()->json(['data' => $detail->for('seller', $seller->id, $seller->name, $seller->company_id, $request->period(), $request->salesFilters(), $request->level())]);
    }

    /**
     * @response array{data: array{subject: array{id: int, name: string, company_id: int, kind: string}, level: string, summary: array{subject: array{id: int, name: string, company_id: int, is_active: bool}, rank: int|null, net_total: float, share_pct: float|null, documents: int, products_sold: int, categories_sold: int, categories_catalog: int, category_coverage_pct: float|null, lines_sold: int, lines_catalog: int, line_coverage_pct: float|null, groups_sold: int, top5_share_pct: float|null}|null, ranking: array{items: list<array{key: string, name: string, net_total: float, units: float, documents: int, customers: int, rank: int, share_pct: float|null, previous_net_total: float, growth_pct: float|null, team_share_pct: float|null, mix_index: float|null}>, total_items: int, truncated: bool, net_total: float}, gaps: array{categories: list<array{key: string, name: string, net_total: float, share_pct: float|null, customers: int}>, lines: list<array{key: string, name: string, net_total: float, share_pct: float|null, customers: int}>}, star_products: array{top: int, sold: int, missing: list<array{key: string, name: string, net_total: float, units: float, documents: int, customers: int}>}}}
     */
    public function operator(ProductMixRequest $request, Operator $operator, ProductMixDetail $detail): JsonResponse
    {
        abort_unless($this->scope->allows($request->user(), $operator->company_id), 404);
        $this->audit->viewed($request->user(), 'product_mix_operator', ['operator_id' => $operator->id, 'company_id' => $operator->company_id] + $request->auditFilters());

        return response()->json(['data' => $detail->for('operator', $operator->id, $operator->name, $operator->company_id, $request->period(), $request->salesFilters(), $request->level())]);
    }
}
