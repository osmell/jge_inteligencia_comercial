<?php

namespace App\Services;

use App\Models\InsightRun;
use App\Models\User;
use Carbon\CarbonInterface;

/** Deja constancia de cada cálculo de inteligencia comercial con el periodo, los parámetros vigentes y un resumen del resultado. */
class InsightRunLogger
{
    public function __construct(private readonly InsightParameters $parameters) {}

    /**
     * @param  array<string, mixed>|null  $summary
     */
    public function record(int $companyId, string $analysis, string $status, ?CarbonInterface $from, ?CarbonInterface $to, ?array $summary = null, ?string $note = null, ?User $user = null): InsightRun
    {
        return InsightRun::create([
            'company_id' => $companyId, 'analysis' => $analysis, 'status' => $status, 'period_from' => $from?->toDateString(), 'period_to' => $to?->toDateString(),
            'parameters' => $this->parameters->values(), 'summary' => $summary, 'note' => $note, 'user_id' => $user?->id, 'computed_at' => now(),
        ]);
    }
}
