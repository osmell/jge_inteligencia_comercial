<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InsightRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cohortes de clientes por mes de su primera compra y retención mensual: de los clientes de la cohorte, qué porcentaje volvió a
 * comprar en cada mes siguiente. Reglas: el primer mes del historial agrupa a clientes que ya existían antes de que hubiera datos,
 * así que se muestra aparte y no entra en el promedio; el mes de la última venta se considera incompleto y no se mide; las celdas
 * que todavía no se pueden observar quedan vacías.
 */
class CohortAnalysis
{
    public function __construct(private readonly InsightParameters $parameters, private readonly InsightAvailability $availability, private readonly InsightRunLogger $runs) {}

    public function compute(Company $company, ?User $user = null, ?CarbonImmutable $today = null): InsightRun
    {
        $today ??= CarbonImmutable::today();

        if (! $this->availability->isAvailable($company->id, 'cohorts', $today)) {
            return $this->runs->record($company->id, 'cohorts', InsightRun::INSUFFICIENT, null, null, null, 'Historial insuficiente para calcular cohortes.', $user);
        }

        $month = 'substr(cast(issued_on as varchar(10)), 1, 7)';
        $rows = DB::table('sales_documents')->where('company_id', $company->id)->groupBy('customer_code', DB::raw($month))->selectRaw("customer_code, {$month} as month")->get();
        $first = DB::table('sales_documents')->where('company_id', $company->id)->selectRaw('min(issued_on) as first_on, max(issued_on) as last_on')->first();
        $firstMonth = CarbonImmutable::parse($first->first_on)->format('Y-m');
        $lastMonth = CarbonImmutable::parse($first->last_on)->format('Y-m');

        /** @var array<string, array<string, true>> $byCustomer cliente → meses con compra */
        $byCustomer = [];
        foreach ($rows as $r) {
            $byCustomer[$r->customer_code][$r->month] = true;
        }

        $depth = $this->parameters->get('cohorts.retention_months');
        $cohorts = [];
        foreach ($byCustomer as $months) {
            $months = array_keys($months);
            sort($months);
            $cohort = $months[0];
            $cohorts[$cohort]['customers'] = ($cohorts[$cohort]['customers'] ?? 0) + 1;
            foreach ($months as $m) {
                $k = $this->offset($cohort, $m);
                if ($k >= 0 && $k <= $depth) {
                    $cohorts[$cohort]['active'][$k] = ($cohorts[$cohort]['active'][$k] ?? 0) + 1;
                }
            }
        }
        ksort($cohorts);

        $result = [];
        $weighted = array_fill(0, $depth + 1, [0, 0]);   // [clientes retenidos, clientes observables]
        foreach ($cohorts as $cohort => $c) {
            $prior = $cohort === $firstMonth;
            $retention = [];
            for ($k = 0; $k <= $depth; $k++) {
                $observable = $this->offset($cohort, $lastMonth) > $k;     // el mes k debe ser anterior al mes (incompleto) de la última venta
                $retention[] = $observable ? round(($c['active'][$k] ?? 0) / $c['customers'] * 100, 1) : null;
                if ($observable && ! $prior) {
                    $weighted[$k][0] += $c['active'][$k] ?? 0;
                    $weighted[$k][1] += $c['customers'];
                }
            }
            $result[] = ['month' => $cohort, 'customers' => $c['customers'], 'prior' => $prior, 'retention' => $retention];
        }

        return $this->runs->record($company->id, 'cohorts', InsightRun::OK, CarbonImmutable::parse($first->first_on), CarbonImmutable::parse($first->last_on), [
            'depth' => $depth, 'first_month' => $firstMonth, 'last_month' => $lastMonth, 'cohorts' => $result,
            'average' => array_map(fn (array $w) => $w[1] > 0 ? round($w[0] / $w[1] * 100, 1) : null, $weighted),
        ], null, $user);
    }

    /** Meses entre dos «AAAA-MM» (b − a). */
    private function offset(string $a, string $b): int
    {
        return ((int) substr($b, 0, 4) - (int) substr($a, 0, 4)) * 12 + ((int) substr($b, 5, 2) - (int) substr($a, 5, 2));
    }
}
