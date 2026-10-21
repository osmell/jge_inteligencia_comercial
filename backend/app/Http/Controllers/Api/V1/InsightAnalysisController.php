<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\InsightRun;
use App\Models\Role;
use App\Services\CohortAnalysis;
use App\Services\ConcentrationAnalysis;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Concentración y cohortes: se leen del último cálculo registrado de la compañía. Son cifras de toda la compañía, así que no se
 * ofrecen a los vendedores (que solo ven sus clientes).
 */
class InsightAnalysisController extends Controller
{
    public function __construct(private readonly ConcentrationAnalysis $concentration, private readonly CohortAnalysis $cohorts) {}

    public function concentration(Request $request): JsonResponse
    {
        return $this->show($request, 'concentration');
    }

    public function cohorts(Request $request): JsonResponse
    {
        return $this->show($request, 'cohorts');
    }

    public function recalculate(Request $request, string $analysis): JsonResponse
    {
        $this->guard($request);
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1']]);
        $company = Company::query()->findOrFail($data['company_id']);
        $run = $analysis === 'concentration' ? $this->concentration->compute($company, $request->user()) : $this->cohorts->compute($company, $request->user());

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
        abort_if($request->user()->role->slug === Role::VENDEDOR, 403, 'Este análisis es de toda la compañía y no está disponible para vendedores.');
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
