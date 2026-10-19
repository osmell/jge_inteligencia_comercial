<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\SalesStatsRequest;
use App\Services\SalesStatistics;
use Illuminate\Http\JsonResponse;

/** KPI y desgloses de ventas. Los datos se limitan al ámbito del usuario por el global scope de SalesLine. */
class SalesStatsController extends Controller
{
    public function __construct(private readonly SalesStatistics $stats) {}

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
}
