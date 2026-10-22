<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerSalesReportRequest;
use App\Services\AuditLogger;
use App\Services\CustomerSalesReport;
use App\Services\SalesReportExporter;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Ventas por cliente en un periodo, con quién vendió y quién facturó. */
class CustomerSalesReportController extends Controller
{
    public function __construct(private readonly CustomerSalesReport $report, private readonly SalesReportExporter $exporter, private readonly AuditLogger $audit) {}

    public function index(CustomerSalesReportRequest $request): JsonResponse
    {
        $result = $this->report->page($request->from(), $request->to(), $request->companyId(), $request->branchId(), $request->page());

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'page' => $request->page(), 'has_more' => $result['has_more'], 'people_on_screen' => CustomerSalesReport::PEOPLE_ON_SCREEN,
                'returns' => 'not_included',
            ],
        ]);
    }

    public function export(CustomerSalesReportRequest $request): Response
    {
        $format = $request->validated('format');
        $from = $request->from();
        $to = $request->to();
        $filters = array_filter(['company_id' => $request->companyId(), 'branch_id' => $request->branchId()]);

        $this->audit->record('customers.sales_report_exported', $request->user(), new: [
            'format' => $format, 'from' => $from->toDateString(), 'to' => $to->toDateString(), 'filters' => $filters,
        ]);

        $rows = $this->report->all($from, $to, $request->companyId(), $request->branchId());
        $range = "{$from->toDateString()}_{$to->toDateString()}";

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($rows) {
                $out = fopen('php://output', 'w');
                $this->exporter->writeCustomerSalesCsv($out, $rows);
                fclose($out);
            }, "ventas-por-cliente-{$range}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $path = $this->exporter->buildCustomerSalesWorkbook($rows, $from, $to, $this->exporter->describeFilters($filters), $request->user()->name);

        return response()->download($path, "ventas-por-cliente-{$range}.xlsx")->deleteFileAfterSend();
    }
}
