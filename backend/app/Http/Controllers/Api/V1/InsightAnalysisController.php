<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InsightRun;
use App\Services\CohortAnalysis;
use App\Services\ConcentrationAnalysis;
use App\Services\SeasonalityAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Concentración y cohortes: se leen del último cálculo registrado de la compañía. Son cifras de toda la compañía, así que no se
 * ofrecen a los vendedores (que solo ven sus clientes).
 */
class InsightAnalysisController extends Controller
{
    public function __construct(private readonly ConcentrationAnalysis $concentration, private readonly CohortAnalysis $cohorts, private readonly SeasonalityAnalysis $seasonality) {}

    public function concentration(Request $request): JsonResponse
    {
        return $this->show($request, 'concentration');
    }

    public function cohorts(Request $request): JsonResponse
    {
        return $this->show($request, 'cohorts');
    }

    public function seasonality(Request $request): JsonResponse
    {
        return $this->show($request, 'seasonality');
    }

    public function recalculate(Request $request, string $analysis): JsonResponse
    {
        $this->guard($request);
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        $company = Company::query()->findOrFail($data['company_id']);
        $run = match ($analysis) {
            'concentration' => $this->concentration->compute($company, $request->user()),
            'seasonality' => $this->seasonality->compute($company, $request->user()),
            default => $this->cohorts->compute($company, $request->user()),
        };

        return response()->json(['data' => $this->present($run)]);
    }

    private function show(Request $request, string $analysis): JsonResponse
    {
        $this->guard($request);
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        Company::query()->findOrFail($data['company_id']);
        $run = InsightRun::query()->where(['company_id' => $data['company_id'], 'analysis' => $analysis])->orderByDesc('computed_at')->orderByDesc('id')->first();

        return response()->json(['data' => $run ? $this->present($run) : null]);
    }

    private function guard(Request $request): void
    {
        Gate::authorize('view-company-analysis');
    }

    /** @return array<string, mixed> */
    private function present(InsightRun $run): array
    {
        return [
            'id' => $run->id, 'status' => $run->status, 'computed_at' => $run->computed_at->toIso8601String(), 'period_from' => $run->period_from?->toDateString(), 'period_to' => $run->period_to?->toDateString(),
            'note' => $run->note, 'summary' => $run->summary, 'parameters' => $run->parameters,
        ];
    }
}
