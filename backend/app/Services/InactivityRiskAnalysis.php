<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InsightAlert;
use App\Models\InsightRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Aviso temprano de inactividad, relativo a la frecuencia propia de cada cliente: se compara el tiempo que lleva sin comprar con su
 * intervalo habitual entre compras (la mediana de sus intervalos en los últimos 24 meses). Si pasa más de N veces ese intervalo
 * (N = inactivity.early_warning_multiplier) y todavía no está «perdido» según las reglas de actividad, se avisa antes de que
 * el cliente pase a inactivo. Solo se evalúan clientes con compras suficientes para medir su frecuencia.
 */
class InactivityRiskAnalysis
{
    public const WINDOW_MONTHS = 24;

    public function __construct(
        private readonly InsightParameters $parameters,
        private readonly InsightAvailability $availability,
        private readonly InsightRunLogger $runs,
        private readonly InsightAlertSync $alerts,
        private readonly CustomerActivity $activity,
    ) {}

    public function compute(Company $company, ?User $user = null, ?CarbonImmutable $today = null): InsightRun
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subMonths(self::WINDOW_MONTHS)->addDay();

        if (! $this->availability->isAvailable($company->id, 'inactivity', $today)) {
            return $this->runs->record($company->id, 'inactivity', InsightRun::INSUFFICIENT, $from, $today, null, 'Historial insuficiente para medir el riesgo de inactividad.', $user);
        }

        $multiplier = $this->parameters->get('inactivity.early_warning_multiplier');
        $minPurchases = $this->parameters->get('inactivity.min_purchases');
        $lostDays = $this->activity->rules()['lost_days'];

        $days = [];
        $names = [];
        foreach (DB::table('sales_documents')->where('company_id', $company->id)->whereBetween('issued_on', [$from->toDateString(), $today->toDateString()])
            ->groupBy('customer_code', 'issued_on')->selectRaw('customer_code, max(customer_name) as name, issued_on')->orderBy('issued_on')->get() as $r) {
            $days[$r->customer_code][] = CarbonImmutable::parse($r->issued_on);
            $names[$r->customer_code] = (string) $r->name;
        }

        $evaluated = 0;
        $candidates = [];
        foreach ($days as $code => $dates) {
            if (count($dates) < $minPurchases) {
                continue;
            }
            $evaluated++;
            $intervals = [];
            for ($i = 1; $i < count($dates); $i++) {
                $intervals[] = (int) $dates[$i - 1]->diffInDays($dates[$i]);
            }
            $typical = $this->median($intervals);
            $last = end($dates);
            $since = (int) $last->diffInDays($today);
            if ($typical <= 0 || $since <= $multiplier * $typical || $since > $lostDays) {
                continue;
            }

            $ratio = $since / $typical;
            $candidates[] = [
                'customer_code' => (string) $code, 'customer_name' => $names[$code], 'type' => InsightAlert::INACTIVITY_RISK, 'severity' => $ratio >= 4 ? 'high' : ($ratio >= 3 ? 'medium' : 'low'),
                'dedupe_key' => "inactivity_risk:{$code}",
                'facts' => ['purchases' => count($dates), 'typical_interval_days' => $typical, 'days_since_last' => $since, 'last_purchase' => $last->toDateString(), 'multiplier' => $multiplier, 'lost_days' => $lostDays],
                'explanation' => "Suele comprar cada {$typical} días (mediana de {$this->plural(count($dates) - 1, 'intervalo')}) y lleva {$since} días sin comprar desde el {$last->toDateString()}: más de {$multiplier} veces su intervalo habitual, y todavía no se considera perdido ({$lostDays} días).",
            ];
        }

        $run = $this->runs->record($company->id, 'inactivity', InsightRun::OK, $from, $today, ['customers_evaluated' => $evaluated, 'alerts' => count($candidates)], null, $user);
        $sync = $this->alerts->sync($run, [InsightAlert::INACTIVITY_RISK], $candidates);
        // El resultado de la sincronización se anota en el resumen del mismo cálculo (el modelo es inmutable, de ahí la consulta directa).
        DB::table('insight_runs')->where('id', $run->id)->update(['summary' => json_encode(($run->summary ?? []) + ['sync' => $sync])]);

        return InsightRun::query()->findOrFail($run->id);
    }

    /** @param  list<int>  $values */
    private function median(array $values): float
    {
        sort($values);
        $n = count($values);

        return $n === 0 ? 0.0 : ($n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2);
    }

    private function plural(int $n, string $word): string
    {
        return $n === 1 ? "1 {$word}" : "{$n} {$word}s";
    }
}
