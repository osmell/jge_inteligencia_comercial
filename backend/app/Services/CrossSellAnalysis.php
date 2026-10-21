<?php

namespace App\Services;

use App\Models\Company;
use App\Models\InsightRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Venta cruzada por asociación entre categorías (o líneas). Para cada cliente se toma el conjunto de categorías que compró en el
 * periodo; una regla A→B dice qué porcentaje de los clientes de A compra también B (confianza) y cuántas veces más que el cliente
 * promedio (elevación = confianza ÷ proporción de clientes que compran B). Solo se guardan reglas con respaldo suficiente
 * (clientes mínimos, confianza mínima y elevación mayor que 1). La oportunidad de un cliente es una regla cuyo antecedente compra y
 * cuyo consecuente no; se conservan las de mayor confianza. Es una sugerencia, no una decisión: cada una trae los conteos que la respaldan.
 */
class CrossSellAnalysis
{
    public const UNCLASSIFIED = 'Sin clasificar';

    public function __construct(private readonly InsightParameters $parameters, private readonly InsightAvailability $availability, private readonly InsightRunLogger $runs) {}

    public function compute(Company $company, ?User $user = null, ?CarbonImmutable $today = null): InsightRun
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subMonths($this->parameters->get('cross_sell.period_months'))->addDay();

        if (! $this->availability->isAvailable($company->id, 'cross_sell', $today)) {
            return $this->runs->record($company->id, 'cross_sell', InsightRun::INSUFFICIENT, $from, $today, null, 'Historial o facturas insuficientes para la venta cruzada.', $user);
        }

        $baskets = $this->baskets($company->id, $from, $today);
        $minSupport = $this->parameters->get('cross_sell.min_support_customers');
        if (count($baskets) < $minSupport) {
            return $this->runs->record($company->id, 'cross_sell', InsightRun::INSUFFICIENT, $from, $today, ['customers' => count($baskets)], 'Hay '.count($baskets)." clientes con compras clasificadas; se necesitan al menos {$minSupport}.", $user);
        }

        $rules = $this->rules($baskets);

        return DB::transaction(function () use ($company, $user, $from, $today, $baskets, $rules) {
            $opportunities = $this->opportunities($baskets, $rules);
            $run = $this->runs->record($company->id, 'cross_sell', InsightRun::OK, $from, $today, ['customers' => count($baskets), 'rules' => count($rules), 'opportunities' => count($opportunities)], null, $user);

            DB::table('cross_sell_opportunities')->where('company_id', $company->id)->delete();
            DB::table('cross_sell_rules')->where('company_id', $company->id)->delete();
            $ids = [];
            foreach ($rules as $key => $r) {
                $ids[$key] = DB::table('cross_sell_rules')->insertGetId([
                    'insight_run_id' => $run->id, 'company_id' => $company->id, 'antecedent' => $r['a'], 'consequent' => $r['b'], 'antecedent_customers' => $r['na'], 'consequent_customers' => $r['nb'],
                    'both_customers' => $r['both'], 'total_customers' => $r['n'], 'confidence' => round($r['confidence'], 4), 'lift' => round($r['lift'], 4),
                ]);
            }
            foreach (array_chunk($opportunities, 500) as $chunk) {
                DB::table('cross_sell_opportunities')->insert(array_map(fn (array $o) => [
                    'insight_run_id' => $run->id, 'rule_id' => $ids[$o['rule']], 'company_id' => $company->id, 'customer_code' => $o['code'], 'customer_name' => $o['name'],
                ], $chunk));
            }

            return $run;
        });
    }

    /**
     * Categorías que compró cada cliente en el periodo (solo compras netas positivas y categorías clasificadas).
     *
     * @return array<string, array{name: string, categories: array<string, true>}>
     */
    private function baskets(int $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $expr = $this->parameters->get('cross_sell.level') === 1 ? "coalesce(nullif(pr.level2, ''), '".self::UNCLASSIFIED."')" : "coalesce(nullif(pr.level3, ''), l.category_name)";
        $rows = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')
            ->leftJoin('products as pr', fn ($j) => $j->on('pr.company_id', '=', 'l.company_id')->on('pr.code', '=', 'l.product_code'))
            ->where('d.company_id', $companyId)->whereBetween('d.issued_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('d.customer_code', DB::raw($expr))->havingRaw('sum(l.net_total) > 0')
            ->selectRaw("d.customer_code as code, max(d.customer_name) as name, {$expr} as category")->get();

        $baskets = [];
        foreach ($rows as $r) {
            if ($r->category === null || $r->category === '' || $r->category === self::UNCLASSIFIED) {
                continue;
            }
            $baskets[$r->code]['name'] = (string) $r->name;
            $baskets[$r->code]['categories'][(string) $r->category] = true;
        }

        return $baskets;
    }

    /**
     * @param  array<string, array{name: string, categories: array<string, true>}>  $baskets
     * @return array<string, array{a: string, b: string, na: int, nb: int, both: int, n: int, confidence: float, lift: float}>
     */
    private function rules(array $baskets): array
    {
        $n = count($baskets);
        $count = [];
        $pairs = [];
        foreach ($baskets as $b) {
            $categories = array_keys($b['categories']);
            foreach ($categories as $a) {
                $count[$a] = ($count[$a] ?? 0) + 1;
                foreach ($categories as $c) {
                    if ($a !== $c) {
                        $pairs[$a][$c] = ($pairs[$a][$c] ?? 0) + 1;
                    }
                }
            }
        }

        $minSupport = $this->parameters->get('cross_sell.min_support_customers');
        $minConfidence = $this->parameters->get('cross_sell.min_confidence_pct') / 100;
        $rules = [];
        foreach ($pairs as $a => $targets) {
            foreach ($targets as $b => $both) {
                $confidence = $both / $count[$a];
                $lift = $confidence / ($count[$b] / $n);
                if ($both >= $minSupport && $confidence >= $minConfidence && $lift > 1) {
                    $rules["{$a}→{$b}"] = ['a' => (string) $a, 'b' => (string) $b, 'na' => $count[$a], 'nb' => $count[$b], 'both' => $both, 'n' => $n, 'confidence' => $confidence, 'lift' => $lift];
                }
            }
        }
        uasort($rules, fn (array $x, array $y) => [$y['confidence'], $y['lift'], $x['a']] <=> [$x['confidence'], $x['lift'], $y['a']]);

        return $rules;
    }

    /**
     * @param  array<string, array{name: string, categories: array<string, true>}>  $baskets
     * @param  array<string, array<string, mixed>>  $rules
     * @return list<array{code: string, name: string, rule: string}>
     */
    private function opportunities(array $baskets, array $rules): array
    {
        $max = $this->parameters->get('cross_sell.max_per_customer');
        $result = [];
        foreach ($baskets as $code => $b) {
            $best = [];     // consecuente → regla de mayor confianza (las reglas ya vienen ordenadas)
            foreach ($rules as $key => $r) {
                if (isset($b['categories'][$r['a']]) && ! isset($b['categories'][$r['b']]) && ! isset($best[$r['b']])) {
                    $best[$r['b']] = $key;
                }
            }
            foreach (array_slice($best, 0, $max) as $key) {
                $result[] = ['code' => (string) $code, 'name' => $b['name'], 'rule' => $key];
            }
        }

        return $result;
    }
}
