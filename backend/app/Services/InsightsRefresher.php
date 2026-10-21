<?php

namespace App\Services;

use App\Models\Company;

/** Recalcula toda la inteligencia comercial de las compañías activas (lo usan el comando y la automatización). */
class InsightsRefresher
{
    public function __construct(
        private readonly RfmSegmentation $rfm,
        private readonly ConcentrationAnalysis $concentration,
        private readonly CohortAnalysis $cohorts,
        private readonly CrossSellAnalysis $crossSell,
        private readonly InactivityRiskAnalysis $inactivity,
        private readonly AnomalyAnalysis $anomalies,
        private readonly SeasonalityAnalysis $seasonality,
    ) {}

    /**
     * @return list<array{company: string, analysis: string, status: string, note: string|null}>
     */
    public function refresh(?int $companyId = null): array
    {
        $services = ['RFM' => $this->rfm, 'Concentración' => $this->concentration, 'Cohortes' => $this->cohorts, 'Venta cruzada' => $this->crossSell, 'Riesgo de inactividad' => $this->inactivity, 'Cambios anormales' => $this->anomalies, 'Estacionalidad' => $this->seasonality];
        $result = [];
        foreach (Company::withoutGlobalScopes()->where('is_active', true)->when($companyId !== null, fn ($q) => $q->whereKey($companyId))->get() as $company) {
            foreach ($services as $label => $service) {
                $run = $service->compute($company);
                $result[] = ['company' => $company->name, 'analysis' => $label, 'status' => $run->status, 'note' => $run->note];
            }
        }

        return $result;
    }
}
