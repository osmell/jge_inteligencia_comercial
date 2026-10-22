<?php

declare(strict_types=1);

namespace App\Services;

use App\Imports\Decimal;
use App\Models\Company;
use App\Models\InsightAlert;
use App\Models\InsightRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Segmentación RFM por compañía: recencia (días desde la última compra), frecuencia (días con compra) y valor (venta neta menos
 * devoluciones) del periodo, cada una puntuada de 1 a 5 con quintiles entre los clientes de la compañía. Los segmentos salen de
 * reglas fijas sobre esas puntuaciones, de modo que cada cliente tiene una explicación comprobable. No se calcula si la compañía no
 * tiene historial suficiente ni clientes suficientes con compras. Es independiente del scoring de crédito y de la clasificación ABC.
 *
 * Reglas (R = puntuación de recencia; FM = promedio de frecuencia y valor):
 *  Nuevo: primera compra reciente y pocas compras · Campeón: R ≥ 4 y FM ≥ 4 · Leal: R ≥ 3 y FM ≥ 3 · Prometedor: R ≥ 3 y FM < 3 ·
 *  En riesgo: R ≤ 2 y FM ≥ 3 · Dormido: R = 2 y FM < 3 · Perdido: R = 1 y FM < 3.
 */
class RfmSegmentation
{
    public const SEGMENTS = [
        'new' => ['label' => 'Nuevos', 'rule' => 'Primera compra reciente y pocas compras.'],
        'champion' => ['label' => 'Campeones', 'rule' => 'Compraron hace poco, con frecuencia y con valor altos (recencia ≥ 4 y frecuencia/valor ≥ 4).'],
        'loyal' => ['label' => 'Leales', 'rule' => 'Compran con regularidad y valor medio o alto (recencia ≥ 3 y frecuencia/valor ≥ 3).'],
        'promising' => ['label' => 'Prometedores', 'rule' => 'Compraron hace poco, pero con poca frecuencia o valor (recencia ≥ 3 y frecuencia/valor < 3).'],
        'at_risk' => ['label' => 'En riesgo', 'rule' => 'Eran buenos clientes y llevan tiempo sin comprar (recencia ≤ 2 y frecuencia/valor ≥ 3).'],
        'dormant' => ['label' => 'Dormidos', 'rule' => 'Poco valor y sin comprar hace un tiempo (recencia 2 y frecuencia/valor < 3).'],
        'lost' => ['label' => 'Perdidos', 'rule' => 'Poco valor y entre los que más tiempo llevan sin comprar (recencia 1 y frecuencia/valor < 3).'],
    ];

    public function __construct(private readonly InsightParameters $parameters, private readonly InsightAvailability $availability, private readonly InsightRunLogger $runs, private readonly InsightAlertSync $alerts) {}

    /**
     * Calcula y guarda la segmentación de una compañía. Devuelve el registro del cálculo (con estado «insufficient» si no hay datos suficientes).
     */
    public function compute(Company $company, ?User $user = null, ?CarbonImmutable $today = null): InsightRun
    {
        $today ??= CarbonImmutable::today();
        $months = $this->parameters->get('rfm.period_months');
        $from = $today->subMonths($months)->addDay();

        if (! $this->availability->isAvailable($company->id, 'rfm', $today)) {
            return $this->runs->record($company->id, 'rfm', InsightRun::INSUFFICIENT, $from, $today, null, 'Historial insuficiente para la segmentación RFM.', $user);
        }

        $customers = $this->customers($company->id, $from, $today);
        $minimum = $this->parameters->get('rfm.min_customers');
        if (count($customers) < $minimum) {
            return $this->runs->record($company->id, 'rfm', InsightRun::INSUFFICIENT, $from, $today, ['customers' => count($customers)], 'Hay '.count($customers)." clientes con compras en el periodo; se necesitan al menos {$minimum}.", $user);
        }

        $scored = $this->score($customers, $today);

        return DB::transaction(function () use ($company, $user, $from, $today, $scored) {
            $counts = array_fill_keys(array_keys(self::SEGMENTS), 0);
            foreach ($scored as $c) {
                $counts[$c['segment']]++;
            }
            $run = $this->runs->record($company->id, 'rfm', InsightRun::OK, $from, $today, ['customers' => count($scored), 'segments' => $counts], null, $user);

            // Solo se conserva el último cálculo de cada compañía.
            DB::table('rfm_scores')->where('company_id', $company->id)->delete();
            foreach (array_chunk($scored, 500) as $chunk) {
                DB::table('rfm_scores')->insert(array_map(fn (array $c) => [
                    'insight_run_id' => $run->id, 'company_id' => $company->id, 'customer_code' => $c['code'], 'customer_name' => $c['name'], 'first_purchase' => $c['first'], 'last_purchase' => $c['last'],
                    'recency_days' => $c['recency'], 'frequency' => $c['frequency'], 'gross' => Decimal::format($c['gross']), 'returns' => Decimal::format($c['returns']), 'monetary' => Decimal::format($c['monetary']),
                    'r' => $c['r'], 'f' => $c['f'], 'm' => $c['m'], 'segment' => $c['segment'],
                ], $chunk));
            }

            $this->alerts->sync($run, [InsightAlert::RFM_AT_RISK], $this->atRiskAlerts($scored));

            return $run;
        });
    }

    /**
     * Los clientes «En riesgo» (buenos clientes que se enfriaron) también son una recomendación del panel.
     *
     * @param  list<array<string, mixed>>  $scored
     * @return list<array<string, mixed>>
     */
    private function atRiskAlerts(array $scored): array
    {
        $out = [];
        foreach ($scored as $c) {
            if ($c['segment'] !== 'at_risk') {
                continue;
            }
            $out[] = [
                'customer_code' => $c['code'], 'customer_name' => $c['name'], 'type' => InsightAlert::RFM_AT_RISK, 'severity' => $c['m'] >= 4 ? 'high' : 'medium', 'dedupe_key' => "rfm_at_risk:{$c['code']}",
                'facts' => ['r' => $c['r'], 'f' => $c['f'], 'm' => $c['m'], 'recency_days' => $c['recency'], 'frequency' => $c['frequency'], 'last_purchase' => $c['last']],
                'explanation' => 'Segmento «En riesgo»: era un buen cliente y lleva tiempo sin comprar. '.self::explain(['recency_days' => $c['recency'], 'frequency' => $c['frequency'], 'monetary' => Decimal::format($c['monetary']), 'r' => $c['r'], 'f' => $c['f'], 'm' => $c['m'], 'segment' => 'at_risk']),
            ];
        }

        return $out;
    }

    /**
     * Frase que explica por qué un cliente cayó en su segmento.
     *
     * @param  array<string, mixed>  $c  recency_days, frequency, monetary, r, f, m y segment
     */
    public static function explain(array $c): string
    {
        $monetary = number_format((float) $c['monetary'], 2, ',', '.');

        return "Compró por última vez hace {$c['recency_days']} días (recencia {$c['r']}/5), tuvo {$c['frequency']} días con compra (frecuencia {$c['f']}/5) y una venta neta de {$monetary} (valor {$c['m']}/5): ".self::SEGMENTS[$c['segment']]['label'].'.';
    }

    /**
     * @return array<string, array{code: string, name: string, first: string, last: string, frequency: int, gross: int, returns: int}>
     */
    private function customers(int $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sales = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')
            ->where('d.company_id', $companyId)->whereBetween('d.issued_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('d.customer_code')->selectRaw('d.customer_code as code, max(d.customer_name) as name, max(d.issued_on) as last_on, count(distinct d.issued_on) as days, sum(l.net_total) as net')->get();
        $first = DB::table('sales_documents')->where('company_id', $companyId)->groupBy('customer_code')->selectRaw('customer_code, min(issued_on) as first_on')->pluck('first_on', 'customer_code');
        $returns = DB::table('credit_notes')->where('company_id', $companyId)->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_movements as m')->join('credit_movements as r', 'r.reverses_id', '=', 'm.id')->whereColumn('m.credit_note_id', 'credit_notes.id'))
            ->groupBy('customer_code')->selectRaw('customer_code, sum(subtotal) as total')->pluck('total', 'customer_code');

        $result = [];
        foreach ($sales as $s) {
            $result[$s->code] = [
                'code' => (string) $s->code, 'name' => (string) $s->name, 'first' => CarbonImmutable::parse($first[$s->code])->toDateString(), 'last' => CarbonImmutable::parse($s->last_on)->toDateString(),
                'frequency' => (int) $s->days, 'gross' => Decimal::units(number_format((float) $s->net, Decimal::SCALE, '.', '')), 'returns' => Decimal::units(number_format((float) ($returns[$s->code] ?? 0), Decimal::SCALE, '.', '')),
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, array<string, mixed>>  $customers
     * @return list<array<string, mixed>>
     */
    private function score(array $customers, CarbonImmutable $today): array
    {
        $rows = [];
        foreach ($customers as $c) {
            $monetary = max(0, $c['gross'] - $c['returns']);
            $rows[] = $c + ['recency' => (int) CarbonImmutable::parse($c['last'])->diffInDays($today), 'monetary' => $monetary];
        }

        $recency = array_column($rows, 'recency');
        $frequency = array_column($rows, 'frequency');
        $monetary = array_column($rows, 'monetary');
        $newDays = $this->parameters->get('rfm.new_customer_days');

        foreach ($rows as &$row) {
            $row['r'] = $this->quintile($row['recency'], $recency, higherIsBetter: false);
            $row['f'] = $this->quintile($row['frequency'], $frequency, higherIsBetter: true);
            $row['m'] = $this->quintile($row['monetary'], $monetary, higherIsBetter: true);
            $isNew = CarbonImmutable::parse($row['first'])->diffInDays($today) <= $newDays && $row['frequency'] <= 2;
            $row['segment'] = $this->segment($row['r'], ($row['f'] + $row['m']) / 2, $isNew);
        }
        unset($row);

        return $rows;
    }

    /**
     * Puntuación 1 a 5 por quintiles: 1 + 5 × (clientes estrictamente peores) ÷ N, con tope en 5. Los empates reciben la misma puntuación.
     *
     * @param  list<int>  $all
     */
    private function quintile(int $value, array $all, bool $higherIsBetter): int
    {
        $worse = count(array_filter($all, fn (int $x) => $higherIsBetter ? $x < $value : $x > $value));

        return min(5, 1 + intdiv(5 * $worse, count($all)));
    }

    private function segment(int $r, float $fm, bool $isNew): string
    {
        return match (true) {
            $isNew => 'new',
            $r >= 4 && $fm >= 4 => 'champion',
            $r >= 3 && $fm >= 3 => 'loyal',
            $r >= 3 => 'promising',
            $fm >= 3 => 'at_risk',
            $r === 2 => 'dormant',
            default => 'lost',
        };
    }
}
