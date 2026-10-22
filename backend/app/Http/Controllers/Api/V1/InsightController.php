<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\InsightRun;
use App\Services\InsightAvailability;
use App\Services\InsightParameters;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Estado de la inteligencia comercial (qué análisis están disponibles), sus parámetros y el registro de cálculos. */
class InsightController extends Controller
{
    public function __construct(private readonly InsightAvailability $availability, private readonly InsightParameters $parameters) {}

    public function status(): JsonResponse
    {
        return response()->json(['data' => $this->availability->overview(), 'meta' => ['stale_after_days' => (int) config('insights.stale_after_days')]]);
    }

    public function parameters(): JsonResponse
    {
        return response()->json(['data' => $this->parameters->describe()]);
    }

    public function updateParameters(Request $request): JsonResponse
    {
        $data = $request->validate(['values' => ['required', 'array', 'min:1']]);
        $this->parameters->update($request->user(), $data['values']);

        return response()->json(['data' => $this->parameters->describe()]);
    }

    public function runs(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1'], 'analysis' => ['nullable', Rule::in(array_keys((array) config('insights.analyses')))], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $rows = InsightRun::query()->with([])->when(! empty($data['company_id']), fn (Builder $q) => $q->where('company_id', $data['company_id']))
            ->when(! empty($data['analysis']), fn (Builder $q) => $q->where('analysis', $data['analysis']))
            ->orderByDesc('computed_at')->orderByDesc('id')->offset(($page - 1) * 25)->limit(26)->get();

        return response()->json([
            'data' => $rows->take(25)->map(fn (InsightRun $r) => [
                'id' => $r->id, 'company_id' => $r->company_id, 'analysis' => $r->analysis, 'status' => $r->status, 'period_from' => $r->period_from?->toDateString(), 'period_to' => $r->period_to?->toDateString(),
                'computed_at' => $r->computed_at->toIso8601String(), 'parameters' => $r->parameters, 'summary' => $r->summary, 'note' => $r->note,
            ])->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > 25],
        ]);
    }
}
