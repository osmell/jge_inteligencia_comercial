<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Services\AnomalyAnalysis;
use App\Services\CohortAnalysis;
use App\Services\ConcentrationAnalysis;
use App\Services\CrossSellAnalysis;
use App\Services\InactivityRiskAnalysis;
use App\Services\RfmSegmentation;
use Illuminate\Console\Command;

class RefreshInsights extends Command
{
    protected $signature = 'insights:refresh {--company= : Id de la compañía}';

    protected $description = 'Recalcula la inteligencia comercial (RFM, concentración, cohortes, venta cruzada, riesgo de inactividad y cambios anormales) de las compañías activas; cada cálculo queda registrado.';

    public function handle(RfmSegmentation $rfm, ConcentrationAnalysis $concentration, CohortAnalysis $cohorts, CrossSellAnalysis $crossSell, InactivityRiskAnalysis $inactivity, AnomalyAnalysis $anomalies): int
    {
        $companies = Company::withoutGlobalScopes()->where('is_active', true)->when($this->option('company'), fn ($q) => $q->whereKey((int) $this->option('company')))->get();
        foreach ($companies as $company) {
            foreach (['RFM' => $rfm, 'Concentración' => $concentration, 'Cohortes' => $cohorts, 'Venta cruzada' => $crossSell, 'Riesgo de inactividad' => $inactivity, 'Cambios anormales' => $anomalies] as $label => $service) {
                $run = $service->compute($company);
                $this->line("{$company->name} · {$label}: ".($run->status === 'ok' ? 'calculado' : (string) $run->note));
            }
        }

        return self::SUCCESS;
    }
}
