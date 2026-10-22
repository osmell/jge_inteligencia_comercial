<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\SellerGoalsRequest;
use App\Services\AuditLogger;
use App\Services\SalesReportExporter;
use App\Services\SellerGoals;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Metas mensuales por vendedor: avance (sales.view), sugerencias y captura (goals.manage). */
class SellerGoalsController extends Controller
{
    public function __construct(private readonly SellerGoals $goals, private readonly SalesReportExporter $exporter, private readonly AuditLogger $audit) {}

    public function index(SellerGoalsRequest $request): JsonResponse
    {
        $result = $this->goals->progress($request->user(), $request->validated('month'), $request->integer('company_id') ?: null);

        return response()->json([
            'data' => $result['rows'],
            'meta' => [
                'month' => $result['month'], 'state' => $result['state'], 'totals' => $result['totals'],
                'thresholds' => [
                    'warning_pct' => SellerGoals::WARNING_PCT, 'goal_pct' => SellerGoals::GOAL_PCT,
                    'pace_alert_pct' => SellerGoals::PACE_ALERT_PCT,
                ],
            ],
        ]);
    }

    public function suggestions(SellerGoalsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->goals->suggestions(
            $request->user(),
            $request->validated('month'),
            (int) ($request->validated('months') ?? 3),
            (float) ($request->validated('growth_pct') ?? 0),
            $request->integer('company_id') ?: null,
        )]);
    }

    public function update(SellerGoalsRequest $request): JsonResponse
    {
        $changed = $this->goals->save($request->user(), $request->validated('month'), $request->validated('goals'), $this->audit);

        return response()->json(['data' => ['changed' => $changed]]);
    }

    /** Descarga las metas del mes en CSV o PDF. Queda registrado en la auditoría. */
    public function export(SellerGoalsRequest $request): Response
    {
        $month = $request->validated('month');
        $companyId = $request->integer('company_id') ?: null;
        $format = $request->validated('format') ?? 'csv';
        $progress = $this->goals->progress($request->user(), $month, $companyId);
        $rows = $progress['rows'];

        $this->audit->record('goals.exported', $request->user(), new: ['month' => $month, 'format' => $format, 'company_id' => $companyId, 'rows' => count($rows)]);

        if ($format === 'pdf') {
            return response($this->exporter->buildGoalsPdf($progress, $request->user()->name), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "attachment; filename=\"metas-{$month}.pdf\"",
            ]);
        }

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            $this->exporter->writeGoalsCsv($out, $rows);
            fclose($out);
        }, "metas-{$month}.csv", ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
