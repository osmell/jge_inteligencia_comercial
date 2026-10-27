<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Company;
use App\Models\InsightRun;
use App\Models\User;
use App\Services\Sales\SalesBasis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Estacionalidad de la venta neta por mes del año, a nivel de compañía y de las líneas con más venta. Para no confundir una
 * tendencia de crecimiento con una temporada, la serie mensual se divide primero por su tendencia lineal; el índice de cada mes
 * del año es el promedio de esos cocientes, escalado para que el promedio de los doce sea 100. Solo se usan meses completos (se
 * descartan el primer y el último mes del historial, que suelen estar incompletos), cada mes del año necesita al menos dos
 * observaciones y solo se activa con el historial mínimo (24 meses por omisión). Incluye la comparación contra el mismo mes del año anterior.
 */
class SeasonalityAnalysis
{
    private const MIN_OBSERVATIONS = 2;

    private const MIN_MONTHS_WITH_SALES = 18;

    public function __construct(private readonly InsightParameters $parameters, private readonly InsightAvailability $availability, private readonly InsightRunLogger $runs) {}

    public function compute(Company $company, ?User $user = null, ?CarbonImmutable $today = null): InsightRun
    {
        $today ??= CarbonImmutable::today();

        if (! $this->availability->isAvailable($company->id, 'seasonality', $today)) {
            return $this->runs->record($company->id, 'seasonality', InsightRun::INSUFFICIENT, null, null, null, 'Historial insuficiente para calcular la estacionalidad.', $user);
        }

        $first = CarbonImmutable::parse((string) DB::table('sales_documents')->where('company_id', $company->id)->min('issued_on'));
        $last = CarbonImmutable::parse((string) DB::table('sales_documents')->where('company_id', $company->id)->max('issued_on'));
        $months = $this->months($first->startOfMonth()->addMonth(), $last->startOfMonth()->subMonth());      // solo meses completos
        if (count($months) < 24 - 2) {
            return $this->runs->record($company->id, 'seasonality', InsightRun::INSUFFICIENT, $first, $last, null, 'Hay '.count($months).' meses completos de ventas; se necesitan al menos 22 para que cada mes del año tenga dos observaciones.', $user);
        }

        $series = $this->companySeries($company->id, $months);
        $result = $this->index($months, $series);
        $lines = [];
        foreach ($this->topLines($company->id, $months) as $line => $values) {
            if (count(array_filter($values, fn (float $v) => $v > 0)) < self::MIN_MONTHS_WITH_SALES) {
                continue;
            }
            $lines[] = ['name' => $line] + $this->index($months, $values);
        }

        return $this->runs->record($company->id, 'seasonality', InsightRun::OK, CarbonImmutable::parse($months[0].'-01'), CarbonImmutable::parse(end($months).'-01')->endOfMonth(), [
            'months' => count($months), 'first_month' => $months[0], 'last_month' => end($months), 'company' => $result, 'lines' => $lines,
            'yoy' => $this->yearOverYear($months, $series), 'marked_points' => $this->parameters->get('seasonality.marked_points'),
        ], null, $user);
    }

    /**
     * Índice estacional de una serie mensual.
     *
     * @param  list<string>  $months  «AAAA-MM» consecutivos
     * @param  array<int, float>  $values  venta por posición del mes
     * @return array{indices: list<float|null>, observations: list<int>, peak: int|null, trough: int|null, amplitude: float|null, marked: bool}
     */
    private function index(array $months, array $values): array
    {
        $n = count($months);
        $mean = array_sum($values) / $n;
        // Tendencia lineal por mínimos cuadrados; si en algún punto no es positiva, se usa el promedio.
        $tMean = ($n - 1) / 2;
        $cov = 0.0;
        $var = 0.0;
        foreach ($values as $t => $y) {
            $cov += ($t - $tMean) * ($y - $mean);
            $var += ($t - $tMean) ** 2;
        }
        $slope = $var > 0 ? $cov / $var : 0.0;
        $trend = fn (int $t) => $mean + $slope * ($t - $tMean);
        $usable = min($trend(0), $trend($n - 1)) > 0;

        $ratios = array_fill(1, 12, []);
        foreach ($months as $t => $m) {
            $base = $usable ? $trend($t) : $mean;
            if ($base > 0) {
                $ratios[(int) substr($m, 5, 2)][] = $values[$t] / $base;
            }
        }
        $raw = [];
        $observations = [];
        for ($m = 1; $m <= 12; $m++) {
            $observations[] = count($ratios[$m]);
            $raw[$m] = count($ratios[$m]) >= self::MIN_OBSERVATIONS ? array_sum($ratios[$m]) / count($ratios[$m]) : null;
        }
        $known = array_filter($raw, fn ($v) => $v !== null);
        $scale = $known === [] ? 0.0 : array_sum($known) / count($known);
        $indices = [];
        foreach ($raw as $v) {
            $indices[] = $v === null || $scale <= 0 ? null : round($v / $scale * 100, 1);
        }

        $present = array_filter($indices, fn ($v) => $v !== null);
        $peak = $present === [] ? null : (int) array_search(max($present), $indices, true) + 1;
        $trough = $present === [] ? null : (int) array_search(min($present), $indices, true) + 1;
        $amplitude = $present === [] ? null : round(max($present) - min($present), 1);

        return ['indices' => $indices, 'observations' => $observations, 'peak' => $peak, 'trough' => $trough, 'amplitude' => $amplitude, 'marked' => $amplitude !== null && $amplitude >= $this->parameters->get('seasonality.marked_points')];
    }

    /**
     * Comparación de los últimos meses completos contra el mismo mes del año anterior.
     *
     * @param  list<string>  $months
     * @param  array<int, float>  $series
     * @return list<array{month: string, value: string, previous: string, change_pct: float|null}>
     */
    private function yearOverYear(array $months, array $series): array
    {
        $position = array_flip($months);
        $out = [];
        foreach (array_slice($months, -12) as $m) {
            $previous = CarbonImmutable::parse($m.'-01')->subYear()->format('Y-m');
            if (! isset($position[$previous])) {
                continue;
            }
            $now = $series[$position[$m]];
            $before = $series[$position[$previous]];
            $out[] = ['month' => $m, 'value' => number_format($now, 2, '.', ''), 'previous' => number_format($before, 2, '.', ''), 'change_pct' => $before > 0 ? round(($now - $before) / $before * 100, 1) : null];
        }

        return $out;
    }

    /** @return list<string> */
    private function months(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $out = [];
        for ($m = $from->startOfMonth(); $m <= $to->startOfMonth(); $m = $m->addMonth()) {
            $out[] = $m->format('Y-m');
        }

        return $out;
    }

    /**
     * @param  list<string>  $months
     * @return array<int, float>
     */
    private function companySeries(int $companyId, array $months): array
    {
        $month = 'substr(cast(d.issued_on as varchar(10)), 1, 7)';
        $sales = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')->where('d.company_id', $companyId)
            ->whereBetween('d.issued_on', [$months[0].'-01', CarbonImmutable::parse(end($months).'-01')->endOfMonth()->toDateString()])
            ->tap(fn ($q) => SalesBasis::scope($q, 'l'))
            ->groupBy(DB::raw($month))->selectRaw("{$month} as month, sum(".SalesBasis::net('l').') as net')->pluck('net', 'month');

        return array_map(fn (string $m) => (float) ($sales[$m] ?? 0), $months);
    }

    /**
     * Las líneas con más venta y su serie mensual.
     *
     * @param  list<string>  $months
     * @return array<string, array<int, float>>
     */
    private function topLines(int $companyId, array $months): array
    {
        $month = 'substr(cast(d.issued_on as varchar(10)), 1, 7)';
        $line = "coalesce(nullif(pr.level2, ''), 'Sin clasificar')";
        $rows = DB::table('sales_documents as d')->join('sales_lines as l', 'l.sales_document_id', '=', 'd.id')
            ->leftJoin('products as pr', fn ($j) => $j->on('pr.company_id', '=', 'l.company_id')->on('pr.code', '=', 'l.product_code'))
            ->where('d.company_id', $companyId)->whereBetween('d.issued_on', [$months[0].'-01', CarbonImmutable::parse(end($months).'-01')->endOfMonth()->toDateString()])
            ->tap(fn ($q) => SalesBasis::scope($q, 'l'))
            ->groupBy(DB::raw($line), DB::raw($month))->selectRaw("{$line} as line, {$month} as month, sum(".SalesBasis::net('l').') as net')->get();

        $position = array_flip($months);
        $series = [];
        $totals = [];
        foreach ($rows as $r) {
            if ($r->line === 'Sin clasificar') {
                continue;
            }
            $series[$r->line] ??= array_fill(0, count($months), 0.0);
            $series[$r->line][$position[$r->month]] = (float) $r->net;
            $totals[$r->line] = ($totals[$r->line] ?? 0) + (float) $r->net;
        }
        arsort($totals);

        return array_intersect_key($series, array_slice($totals, 0, $this->parameters->get('seasonality.top_lines'), true));
    }
}
