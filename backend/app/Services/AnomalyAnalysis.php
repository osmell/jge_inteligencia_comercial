<?php

declare(strict_types=1);

namespace App\Services;

use App\Imports\Decimal;
use App\Models\Company;
use App\Models\InsightAlert;
use App\Models\InsightRun;
use App\Models\User;
use App\Services\Sales\SalesBasis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cambios anormales de venta de cada cliente, contra su propio historial:
 *  - Caída o alza: la venta neta del último mes completo contra el promedio de los N meses anteriores (N = anomaly.baseline_months);
 *    solo se evalúan clientes con al menos anomaly.min_purchases días de compra en esos meses de referencia.
 *  - Línea abandonada: una categoría que el cliente compraba en al menos 3 meses distintos y que no compra desde hace N meses,
 *    mientras sigue comprando otras (si dejó de comprar todo, el aviso es el de inactividad).
 * El último mes completo es el anterior al mes de la última venta de la compañía (ese mes está incompleto).
 */
class AnomalyAnalysis
{
    /** Meses antes de la ventana reciente en los que se busca el hábito de compra de una categoría. */
    private const LOOKBACK_MONTHS = 6;

    private const HABIT_MONTHS = 3;

    private const MAX_ABANDONED_PER_CUSTOMER = 3;

    public function __construct(
        private readonly InsightParameters $parameters,
        private readonly InsightAvailability $availability,
        private readonly InsightRunLogger $runs,
        private readonly InsightAlertSync $alerts,
    ) {}

    public function compute(Company $company, ?User $user = null, ?CarbonImmutable $today = null): InsightRun
    {
        $today ??= CarbonImmutable::today();

        if (! $this->availability->isAvailable($company->id, 'anomalies', $today)) {
            return $this->runs->record($company->id, 'anomalies', InsightRun::INSUFFICIENT, null, null, null, 'Historial insuficiente para detectar cambios anormales.', $user);
        }

        $lastSale = DB::table('sales_documents')->where('company_id', $company->id)->max('issued_on');
        $evaluated = CarbonImmutable::parse($lastSale)->startOfMonth()->subMonth();        // último mes completo
        $baselineMonths = $this->parameters->get('anomaly.baseline_months');
        $from = $evaluated->subMonths($baselineMonths + self::LOOKBACK_MONTHS - 1)->startOfMonth();
        $to = $evaluated->endOfMonth();

        $candidates = [...$this->salesChanges($company->id, $evaluated, $baselineMonths), ...$this->abandonedLines($company->id, $evaluated, $baselineMonths, $from, $to)];
        $run = $this->runs->record($company->id, 'anomalies', InsightRun::OK, $from, $to, ['evaluated_month' => $evaluated->format('Y-m'), 'alerts' => count($candidates)], null, $user);
        $sync = $this->alerts->sync($run, [InsightAlert::SALES_DROP, InsightAlert::SALES_RISE, InsightAlert::ABANDONED_LINE], $candidates);
        // El resultado de la sincronización se anota en el resumen del mismo cálculo (el modelo es inmutable, de ahí la consulta directa).
        DB::table('insight_runs')->where('id', $run->id)->update(['summary' => json_encode(($run->summary ?? []) + ['sync' => $sync])]);

        return InsightRun::query()->findOrFail($run->id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function salesChanges(int $companyId, CarbonImmutable $evaluated, int $baselineMonths): array
    {
        $month = 'substr(cast(d.issued_on as varchar(10)), 1, 7)';
        $baselineFrom = $evaluated->subMonths($baselineMonths)->startOfMonth();
        $rows = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')->where('d.company_id', $companyId)
            ->whereBetween('d.issued_on', [$baselineFrom->toDateString(), $evaluated->endOfMonth()->toDateString()])
            ->tap(fn ($q) => SalesBasis::scope($q, 'l'))
            ->groupBy('d.customer_code', DB::raw($month))->selectRaw("d.customer_code as code, max(d.customer_name) as name, {$month} as month, sum(".SalesBasis::net('l').') as net, count(distinct d.issued_on) as days')->get();

        $dropPct = $this->parameters->get('anomaly.drop_pct');
        $risePct = $this->parameters->get('anomaly.rise_pct');
        $minPurchases = $this->parameters->get('anomaly.min_purchases');
        $key = $evaluated->format('Y-m');

        $byCustomer = [];
        foreach ($rows as $r) {
            $byCustomer[$r->code]['name'] = (string) $r->name;
            $byCustomer[$r->code]['months'][$r->month] = ['net' => Decimal::units(number_format((float) $r->net, Decimal::SCALE, '.', '')), 'days' => (int) $r->days];
        }

        $out = [];
        foreach ($byCustomer as $code => $c) {
            $baselineNet = 0;
            $baselineDays = 0;
            for ($i = 1; $i <= $baselineMonths; $i++) {
                $m = $c['months'][$evaluated->subMonths($i)->format('Y-m')] ?? null;
                $baselineNet += $m['net'] ?? 0;
                $baselineDays += $m['days'] ?? 0;
            }
            if ($baselineDays < $minPurchases || $baselineNet <= 0) {
                continue;
            }
            $average = $baselineNet / $baselineMonths;
            $current = $c['months'][$key]['net'] ?? 0;
            $change = ($current - $average) / $average * 100;
            $facts = ['month' => $key, 'current' => Decimal::format($current), 'baseline_average' => Decimal::format((int) round($average)), 'baseline_months' => $baselineMonths, 'baseline_purchase_days' => $baselineDays, 'change_pct' => round($change, 1)];
            $format = fn (int $u) => number_format($u / 10 ** Decimal::SCALE, 2, '.', ',');

            if ($change <= -$dropPct) {
                $out[] = [
                    'customer_code' => (string) $code, 'customer_name' => $c['name'], 'type' => InsightAlert::SALES_DROP, 'severity' => $change <= -75 ? 'high' : ($change <= -60 ? 'medium' : 'low'),
                    'dedupe_key' => "sales_drop:{$code}:{$key}", 'facts' => $facts,
                    'explanation' => "En {$key} compró {$format($current)}, un ".abs(round($change, 1))." % menos que su promedio de los {$baselineMonths} meses anteriores ({$format((int) round($average))}). El aviso se activa con una caída de {$dropPct} % o más.",
                ];
            } elseif ($change >= $risePct) {
                $out[] = [
                    'customer_code' => (string) $code, 'customer_name' => $c['name'], 'type' => InsightAlert::SALES_RISE, 'severity' => 'low',
                    'dedupe_key' => "sales_rise:{$code}:{$key}", 'facts' => $facts,
                    'explanation' => "En {$key} compró {$format($current)}, un ".round($change, 1)." % más que su promedio de los {$baselineMonths} meses anteriores ({$format((int) round($average))}). El aviso se activa con un alza de {$risePct} % o más.",
                ];
            }
        }

        return $out;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function abandonedLines(int $companyId, CarbonImmutable $evaluated, int $baselineMonths, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $month = 'substr(cast(d.issued_on as varchar(10)), 1, 7)';
        $category = "coalesce(nullif(pr.level3, ''), l.category_name)";
        $rows = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')
            ->leftJoin('products as pr', fn ($j) => $j->on('pr.company_id', '=', 'l.company_id')->on('pr.code', '=', 'l.product_code'))
            ->where('d.company_id', $companyId)->whereBetween('d.issued_on', [$from->toDateString(), $to->toDateString()])
            ->tap(fn ($q) => SalesBasis::scope($q, 'l'))
            ->groupBy('d.customer_code', DB::raw($category), DB::raw($month))->havingRaw('sum('.SalesBasis::net('l').') > 0')
            ->selectRaw("d.customer_code as code, max(d.customer_name) as name, {$category} as category, {$month} as month, max(d.issued_on) as last_on")->get();

        $recent = [];      // meses de la ventana reciente
        for ($i = 0; $i < $baselineMonths; $i++) {
            $recent[$evaluated->subMonths($i)->format('Y-m')] = true;
        }

        /** @var array<string, string> $names */
        $names = [];
        /** @var array<string, true> $activeRecent clientes que compraron algo en la ventana reciente */
        $activeRecent = [];
        /** @var array<string, array<string, true>> $recentCategories */
        $recentCategories = [];
        /** @var array<string, array<string, array{months: array<string, true>, last: string}>> $habit */
        $habit = [];
        foreach ($rows as $r) {
            if ($r->category === null || $r->category === '') {
                continue;
            }
            $code = (string) $r->code;
            $category = (string) $r->category;
            $names[$code] = (string) $r->name;
            if (isset($recent[$r->month])) {
                $activeRecent[$code] = true;
                $recentCategories[$code][$category] = true;

                continue;
            }
            $entry = $habit[$code][$category] ?? ['months' => [], 'last' => ''];
            $entry['months'][(string) $r->month] = true;
            $entry['last'] = max($entry['last'], (string) $r->last_on);
            $habit[$code][$category] = $entry;
        }

        $out = [];
        $firstMonth = $evaluated->subMonths($baselineMonths - 1)->format('Y-m');
        foreach ($habit as $code => $categories) {
            if (! isset($activeRecent[$code])) {
                continue;       // dejó de comprar todo: ese caso es el aviso de inactividad
            }
            $lost = array_filter($categories, fn (array $h, string $cat) => count($h['months']) >= self::HABIT_MONTHS && ! isset($recentCategories[$code][$cat]), ARRAY_FILTER_USE_BOTH);
            uasort($lost, fn (array $a, array $b) => count($b['months']) <=> count($a['months']));
            foreach (array_slice($lost, 0, self::MAX_ABANDONED_PER_CUSTOMER, true) as $cat => $h) {
                $months = count($h['months']);
                $out[] = [
                    'customer_code' => (string) $code, 'customer_name' => $names[$code], 'type' => InsightAlert::ABANDONED_LINE, 'severity' => $months >= 5 ? 'high' : ($months >= 4 ? 'medium' : 'low'),
                    'dedupe_key' => "abandoned_line:{$code}:".md5((string) $cat),
                    'facts' => ['category' => (string) $cat, 'months_with_purchases' => $months, 'last_purchase' => $h['last'], 'months_without' => $baselineMonths, 'since_month' => $firstMonth],
                    'explanation' => "Compraba «{$cat}» en {$months} de los {$this->lookback()} meses anteriores (última compra el {$h['last']}) y no la compra desde {$firstMonth}, aunque sigue comprando otras categorías.",
                ];
            }
        }

        return $out;
    }

    private function lookback(): int
    {
        return self::LOOKBACK_MONTHS;
    }
}
