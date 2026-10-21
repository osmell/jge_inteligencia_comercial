<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Services\DashboardService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Dashboard inicial: qué secciones ve la persona y el contenido de cada una (se piden por separado). */
class DashboardController extends Controller
{
    public function __construct(private readonly DashboardService $dashboard) {}

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $profile = $this->dashboard->profile($user);

        return response()->json(['data' => [
            'profile' => $profile['profile'], 'linked_sellers' => $profile['seller_ids'] === null ? null : count($profile['seller_ids']),
            'sections' => $this->dashboard->sectionsFor($user), 'periods' => DashboardService::PERIODS,
            'companies' => Company::query()->whereIn('id', $this->dashboard->companyIds($user))->orderBy('name')->get(['id', 'name'])->all(),
        ] + $this->dashboard->freshness()]);
    }

    public function show(Request $request, string $section): JsonResponse
    {
        $data = $request->validate(['period' => ['nullable', Rule::in(DashboardService::PERIODS)], 'company_id' => ['nullable', 'integer', 'min:1']]);
        $user = $request->user();
        abort_if(! empty($data['company_id']) && ! in_array((int) $data['company_id'], $this->dashboard->companyIds($user), true), 404, 'Compañía no encontrada.');
        $result = $this->dashboard->section($user, $section, $data['period'] ?? 'month', empty($data['company_id']) ? null : (int) $data['company_id']);
        abort_if($result === null, 404, 'Sección no disponible.');

        return response()->json(['data' => $result]);
    }
}
