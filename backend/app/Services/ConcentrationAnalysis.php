<?php

namespace App\Services;

use App\Imports\Decimal;
use App\Models\Company;
use App\Models\InsightRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Concentración de la venta por cliente y por producto en el periodo: cuánto pesan los principales, cuántos suman el 80 %
 * (Pareto), un índice de concentración (Herfindahl-Hirschman, 0 a 10.000) y los clientes que superan el porcentaje de aviso por
 * dependencia (informativo). La venta de los clientes es neta de devoluciones. Solo se calcula con historial suficiente.
 */
class ConcentrationAnalysis
{
    public const TOP = 10;

    public function __construct(private readonly InsightParameters $parameters, private readonly InsightAvailability $availability, private readonly InsightRunLogger $runs) {}

    public function compute(Company $company, ?User $user = null, ?CarbonImmutable $today = null): InsightRun
    {
        $today ??= CarbonImmutable::today();
        $from = $today->subMonths($this->parameters->get('concentration.period_months'))->addDay();

        if (! $this->availability->isAvailable($company->id, 'concentration', $today)) {
            return $this->runs->record($company->id, 'concentration', InsightRun::INSUFFICIENT, $from, $today, null, 'Historial insuficiente para medir la concentración.', $user);
        }

        $customers = $this->customers($company->id, $from, $today);
        $products = $this->products($company->id, $from, $today);
        if ($customers === []) {
            return $this->runs->record($company->id, 'concentration', InsightRun::INSUFFICIENT, $from, $today, null, 'No hay ventas en el periodo analizado.', $user);
        }
        $alert = $this->parameters->get('concentration.alert_pct');

        return $this->runs->record($company->id, 'concentration', InsightRun::OK, $from, $today, [
            'alert_pct' => $alert, 'customers' => $this->summarize($customers, $alert), 'products' => $this->summarize($products, null),
        ], null, $user);
    }

    /**
     * @param  list<array{code: string, name: string, net: int}>  $rows
     * @return array<string, mixed>
     */
    private function summarize(array $rows, ?int $alertPct): array
    {
        usort($rows, fn (array $a, array $b) => [$b['net'], $a['name']] <=> [$a['net'], $b['name']]);
        $total = array_sum(array_column($rows, 'net'));
        $count = count($rows);

        $cumulative = 0;
        $n80 = null;
        $hhi = 0.0;
        $top = [];
        $alerts = [];
        $curve = [];
        foreach ($rows as $i => $r) {
            $share = $total > 0 ? $r['net'] / $total : 0.0;
            $cumulative += $r['net'];
            $hhi += ($share * 100) ** 2;
            if ($n80 === null && $total > 0 && $cumulative / $total >= 0.8) {
                $n80 = $i + 1;
            }
            $entry = ['code' => $r['code'], 'name' => $r['name'], 'net' => Decimal::format($r['net']), 'share_pct' => round($share * 100, 2), 'cumulative_pct' => $total > 0 ? round($cumulative / $total * 100, 2) : 0.0];
            if ($i < self::TOP) {
                $top[] = $entry;
            }
            if ($alertPct !== null && $share * 100 >= $alertPct) {
                $alerts[] = $entry;
            }
        }
        // Curva de Pareto: porcentaje acumulado de la venta al 5 %, 10 %… 100 % de los clientes (o productos).
        $running = 0;
        $idx = 0;
        foreach (range(5, 100, 5) as $pct) {
            $upTo = (int) ceil($count * $pct / 100);
            while ($idx < $upTo) {
                $running += $rows[$idx++]['net'];
            }
            $curve[] = ['entities_pct' => $pct, 'sales_pct' => $total > 0 ? round($running / $total * 100, 2) : 0.0];
        }

        return [
            'count' => $count, 'total' => Decimal::format($total), 'top1_pct' => $top[0]['share_pct'] ?? 0.0,
            'top10_pct' => $total > 0 ? round(array_sum(array_column(array_slice($rows, 0, self::TOP), 'net')) / $total * 100, 2) : 0.0,
            'n80' => $n80 ?? 0, 'n80_pct' => $n80 !== null && $count > 0 ? round($n80 / $count * 100, 1) : 0.0, 'hhi' => (int) round($hhi),
            'top' => $top, 'curve' => $curve, 'alerts' => $alerts,
        ];
    }

    /** @return list<array{code: string, name: string, net: int}> venta neta de devoluciones, solo clientes con venta positiva */
    private function customers(int $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $sales = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')->where('d.company_id', $companyId)
            ->whereBetween('d.issued_on', [$from->toDateString(), $to->toDateString()])->groupBy('d.customer_code')->selectRaw('d.customer_code as code, max(d.customer_name) as name, sum(l.net_total) as net')->get();
        $returns = DB::table('credit_notes')->where('company_id', $companyId)->whereBetween('issued_on', [$from->toDateString(), $to->toDateString()])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_movements as m')->join('credit_movements as r', 'r.reverses_id', '=', 'm.id')->whereColumn('m.credit_note_id', 'credit_notes.id'))
            ->groupBy('customer_code')->selectRaw('customer_code, sum(subtotal) as total')->pluck('total', 'customer_code');

        $rows = [];
        foreach ($sales as $s) {
            $net = $this->units($s->net) - $this->units($returns[$s->code] ?? 0);
            if ($net > 0) {
                $rows[] = ['code' => (string) $s->code, 'name' => (string) $s->name, 'net' => $net];
            }
        }

        return $rows;
    }

    /** @return list<array{code: string, name: string, net: int}> */
    private function products(int $companyId, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = [];
        foreach (DB::table('sales_lines as l')->where('l.company_id', $companyId)->whereBetween('l.issued_on', [$from->toDateString(), $to->toDateString()])
            ->groupBy('l.product_code')->selectRaw('l.product_code as code, max(l.product_name) as name, sum(l.net_total) as net')->get() as $p) {
            $net = $this->units($p->net);
            if ($net > 0) {
                $rows[] = ['code' => (string) $p->code, 'name' => (string) $p->name, 'net' => $net];
            }
        }

        return $rows;
    }

    private function units(mixed $value): int
    {
        return Decimal::units(number_format((float) $value, Decimal::SCALE, '.', ''));
    }
}
