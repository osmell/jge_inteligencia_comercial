<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\InsightRun;
use App\Models\SalesDocument;
use Carbon\CarbonImmutable;

/**
 * Qué análisis de inteligencia comercial se pueden activar en cada compañía. Un análisis solo se activa si hay historial
 * suficiente (meses entre la primera y la última venta, y facturas mínimas cuando aplica); si no, se explica qué falta. Nunca
 * se calcula una predicción «a la fuerza» con pocos datos.
 */
class InsightAvailability
{
    public function __construct(private readonly InsightParameters $parameters) {}

    /**
     * @return list<array<string, mixed>> una entrada por compañía visible para el usuario
     */
    public function overview(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $stats = SalesDocument::query()->selectRaw('company_id, min(issued_on) as first_on, max(issued_on) as last_on, count(*) as documents')->groupBy('company_id')->get()->keyBy('company_id');
        $lastRuns = InsightRun::query()->orderByDesc('computed_at')->orderByDesc('id')->get()->groupBy('company_id')->map(fn ($runs) => $runs->unique('analysis')->keyBy('analysis'));

        $result = [];
        foreach (Company::query()->where('is_active', true)->orderBy('name')->get() as $company) {
            $s = $stats[$company->id] ?? null;
            $first = $s ? CarbonImmutable::parse($s->getAttribute('first_on')) : null;
            $last = $s ? CarbonImmutable::parse($s->getAttribute('last_on')) : null;
            $months = $first && $last ? $this->months($first, $last) : 0;
            $documents = $s ? (int) $s->getAttribute('documents') : 0;
            $staleDays = $last ? (int) $last->diffInDays($today) : null;

            $analyses = [];
            foreach ((array) config('insights.analyses') as $key => $a) {
                $needMonths = $this->parameters->get($a['min_months']);
                $needDocs = isset($a['min_documents']) ? $this->parameters->get($a['min_documents']) : null;
                $missing = [];
                if ($months < $needMonths) {
                    $missing[] = "tiene {$months} de {$needMonths} meses de historial";
                }
                if ($needDocs !== null && $documents < $needDocs) {
                    $missing[] = 'tiene '.number_format($documents, 0, ',', '.').' de '.number_format($needDocs, 0, ',', '.').' facturas';
                }
                $run = $lastRuns[$company->id][$key] ?? null;
                $analyses[] = [
                    'key' => $key, 'label' => $a['label'], 'available' => $missing === [],
                    'reason' => $missing === [] ? null : 'Historial insuficiente: '.implode(' y ', $missing).'.',
                    'required_months' => $needMonths, 'required_documents' => $needDocs,
                    'last_run' => $run ? ['computed_at' => $run->computed_at->toIso8601String(), 'status' => $run->status, 'period_from' => $run->period_from?->toDateString(), 'period_to' => $run->period_to?->toDateString()] : null,
                ];
            }

            $result[] = [
                'company_id' => $company->id, 'company' => $company->name, 'first_sale' => $first?->toDateString(), 'last_sale' => $last?->toDateString(),
                'history_months' => $months, 'documents' => $documents, 'stale' => $staleDays !== null && $staleDays > (int) config('insights.stale_after_days'), 'days_since_last_sale' => $staleDays,
                'analyses' => $analyses,
            ];
        }

        return $result;
    }

    /** Si el análisis está disponible para la compañía. */
    public function isAvailable(int $companyId, string $analysis, ?CarbonImmutable $today = null): bool
    {
        foreach ($this->overview($today) as $c) {
            if ($c['company_id'] === $companyId) {
                return (bool) collect($c['analyses'])->firstWhere('key', $analysis)['available'];
            }
        }

        return false;
    }

    /** Meses completos entre la primera y la última venta. */
    private function months(CarbonImmutable $first, CarbonImmutable $last): int
    {
        return max(0, (int) floor($first->diffInMonths($last)));
    }
}
