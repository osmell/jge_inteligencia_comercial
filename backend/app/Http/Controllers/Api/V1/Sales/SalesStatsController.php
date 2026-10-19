<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesExportRequest;
use App\Http\Requests\SalesStatsRequest;
use App\Services\AuditLogger;
use App\Services\SalesReportExporter;
use App\Services\SalesStatistics;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** KPI y desgloses de ventas. Los datos se limitan al ámbito del usuario por el global scope de SalesLine. */
class SalesStatsController extends Controller
{
    public function __construct(
        private readonly SalesStatistics $stats,
        private readonly SalesReportExporter $exporter,
        private readonly AuditLogger $audit,
    ) {}

    public function summary(SalesStatsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->stats->summary($request->from(), $request->to(), $request->filters())]);
    }

    public function breakdown(SalesStatsRequest $request): JsonResponse
    {
        $result = $this->stats->breakdown(
            $request->validated('dimension'),
            $request->from(),
            $request->to(),
            $request->filters(),
            $request->validated('sort') ?? 'net_total',
            (int) ($request->validated('limit') ?? 20),
        );

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'dimension' => $request->validated('dimension'),
                'truncated' => $result['truncated'],
                'notes' => ['margin' => 'estimated_with_current_cost', 'documents' => 'distinct_per_group'],
            ],
        ]);
    }

    /** Descarga el reporte (CSV de un desglose o Excel completo). Queda registrado en la auditoría. */
    public function export(SalesExportRequest $request): Response
    {
        $format = $request->validated('format');
        $dimension = $request->validated('dimension');
        $from = $request->from();
        $to = $request->to();
        $filters = $request->filters();

        $this->audit->record('sales.exported', $request->user(), new: [
            'format' => $format, 'dimension' => $format === 'csv' ? $dimension : 'all',
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'filters' => $filters,
        ]);

        $range = "{$from->toDateString()}_{$to->toDateString()}";

        if ($format === 'csv') {
            return response()->streamDownload(function () use ($dimension, $from, $to, $filters) {
                $out = fopen('php://output', 'w');
                $this->exporter->writeCsv($out, $dimension, $from, $to, $filters);
                fclose($out);
            }, "ventas-por-{$dimension}-{$range}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
        }

        $path = $this->exporter->buildWorkbook($from, $to, $filters, $request->user()->name);

        return response()->download($path, "ventas-{$range}.xlsx")->deleteFileAfterSend();
    }
}
