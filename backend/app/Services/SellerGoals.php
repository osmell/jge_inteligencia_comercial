<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\SalesLine;
use App\Models\Seller;
use App\Models\SellerGoal;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Metas mensuales por vendedor sobre venta neta (sin IVA).
 *
 * Meta efectiva = meta del mes × días elegibles / días del mes. Los días elegibles son los del mes
 * dentro del alta/baja del vendedor, menos los días de ausencia capturados.
 */
class SellerGoals
{
    /** Semáforo de cumplimiento: rojo por debajo de WARNING_PCT, ámbar hasta GOAL_PCT, verde desde GOAL_PCT. */
    public const WARNING_PCT = 70;

    public const GOAL_PCT = 100;

    /** Alerta de ritmo: vendido / esperado a la fecha por debajo de este porcentaje, una vez transcurrida una parte mínima del mes. */
    public const PACE_ALERT_PCT = 80;

    public const PACE_MIN_ELAPSED = 0.2;

    public function __construct(private readonly DataScope $scope) {}

    /** Primer día del mes de "YYYY-MM". */
    public static function monthStart(string $month): CarbonImmutable
    {
        return CarbonImmutable::createFromFormat('!Y-m', $month);
    }

    /**
     * @return array{month: string, state: string, rows: list<array<string, mixed>>, totals: array<string, mixed>}
     */
    public function progress(User $user, string $month, ?int $companyId = null, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $start = self::monthStart($month);
        $end = $start->endOfMonth()->startOfDay();
        $state = $today->lt($start) ? 'future' : ($today->gt($end) ? 'closed' : 'current');

        $actuals = $this->netByseller($start, $end, $user, $companyId);
        $goals = SellerGoal::query()->where('month', $start->toDateString())->get()->keyBy('seller_id');

        $sellers = $this->sellersQuery($user, $companyId)
            ->where(fn ($q) => $q->where('sellers.is_active', true)
                ->orWhereIn('sellers.id', $goals->keys())
                ->orWhereIn('sellers.id', array_keys($actuals)))
            ->orderBy('sellers.name')
            ->get();

        $rows = [];
        foreach ($sellers as $seller) {
            $rows[] = $this->row($seller, $goals->get($seller->id), $actuals[$seller->id] ?? 0.0, $start, $end, $today, $state);
        }

        return ['month' => $start->format('Y-m'), 'state' => $state, 'rows' => $rows, 'totals' => $this->totals($rows)];
    }

    /**
     * Sugerencia: promedio de venta neta de los últimos $months meses completos con ventas, más el crecimiento indicado.
     *
     * @return list<array{seller_id: int, seller: string, company_id: int, suggested_amount: float, months_used: int, average: float}>
     */
    public function suggestions(User $user, string $month, int $months, float $growthPct, ?int $companyId = null): array
    {
        $target = self::monthStart($month);
        $totals = [];
        $counts = [];

        for ($i = 1; $i <= $months; $i++) {
            $from = $target->subMonthsNoOverflow($i);
            foreach ($this->netByseller($from, $from->endOfMonth()->startOfDay(), $user, $companyId) as $sellerId => $net) {
                if ($net > 0) {
                    $totals[$sellerId] = ($totals[$sellerId] ?? 0) + $net;
                    $counts[$sellerId] = ($counts[$sellerId] ?? 0) + 1;
                }
            }
        }

        $out = [];
        foreach ($this->sellersQuery($user, $companyId)->where('sellers.is_active', true)->orderBy('sellers.name')->get() as $seller) {
            $used = $counts[$seller->id] ?? 0;
            if ($used === 0) {
                continue;
            }
            $average = $totals[$seller->id] / $used;
            $out[] = [
                'seller_id' => $seller->id, 'seller' => $seller->name, 'company_id' => $seller->company_id,
                'average' => round($average, 2), 'months_used' => $used,
                'suggested_amount' => round($average * (1 + $growthPct / 100), 0),
            ];
        }

        return $out;
    }

    /**
     * Guarda metas, ausencias y alta/baja. Una meta con monto nulo se elimina.
     *
     * @param  list<array{seller_id: int, amount?: float|int|string|null, days_off?: int, source?: string, starts_on?: ?string, ends_on?: ?string}>  $items
     * @return int vendedores modificados
     */
    public function save(User $user, string $month, array $items, AuditLogger $audit): int
    {
        $start = self::monthStart($month);
        $changed = 0;

        DB::transaction(function () use ($user, $start, $items, $audit, &$changed) {
            foreach ($items as $item) {
                $seller = $this->sellersQuery($user, null)->where('sellers.id', $item['seller_id'])->firstOrFail();
                $goal = SellerGoal::where(['seller_id' => $seller->id, 'month' => $start->toDateString()])->first();

                $old = [
                    'amount' => $goal?->amount, 'days_off' => $goal?->days_off,
                    'starts_on' => $seller->starts_on?->toDateString(), 'ends_on' => $seller->ends_on?->toDateString(),
                ];

                if (array_key_exists('starts_on', $item) || array_key_exists('ends_on', $item)) {
                    $seller->forceFill([
                        'starts_on' => $item['starts_on'] ?? null,
                        'ends_on' => $item['ends_on'] ?? null,
                    ])->save();
                }

                $amount = $item['amount'] ?? null;
                if ($amount === null || $amount === '') {
                    $goal?->delete();
                } else {
                    $goal = SellerGoal::updateOrCreate(
                        ['seller_id' => $seller->id, 'month' => $start->toDateString()],
                        ['amount' => $amount, 'days_off' => (int) ($item['days_off'] ?? 0), 'source' => $item['source'] ?? 'manual', 'updated_by' => $user->id],
                    );
                }

                $new = [
                    'amount' => $goal?->exists ? $goal->fresh()->amount : null, 'days_off' => $goal?->exists ? $goal->days_off : null,
                    'starts_on' => $seller->starts_on?->toDateString(), 'ends_on' => $seller->ends_on?->toDateString(),
                ];

                if ($old !== $new) {
                    $changed++;
                    $audit->record('goals.updated', $user, auditable: $seller, old: ['month' => $start->format('Y-m')] + $old, new: ['month' => $start->format('Y-m')] + $new + ['source' => $item['source'] ?? 'manual'], companyId: $seller->company_id);
                }
            }
        });

        return $changed;
    }

    /**
     * Vendedores sobre los que el usuario puede ver y fijar metas: compañías a las que tiene acceso completo.
     *
     * @return Builder<Seller>
     */
    public function sellersQuery(User $user, ?int $companyId): Builder
    {
        $query = Seller::query();
        $full = $this->scope->fullCompanyIds($user);

        if ($full !== null) {
            $query->whereIn('sellers.company_id', $full);
        }
        if ($companyId) {
            $query->where('sellers.company_id', $companyId);
        }

        return $query;
    }

    /** @return array<int, float> venta neta por vendedor */
    private function netByseller(CarbonImmutable $from, CarbonImmutable $to, User $user, ?int $companyId): array
    {
        $query = SalesLine::query()
            ->join('sales_documents as sd', 'sd.id', '=', 'sales_lines.sales_document_id')
            ->where('sales_lines.issued_on', '>=', $from->toDateString())
            ->where('sales_lines.issued_on', '<', $to->addDay()->toDateString());

        $full = $this->scope->fullCompanyIds($user);
        if ($full !== null) {
            $query->whereIn('sales_lines.company_id', $full);
        }
        if ($companyId) {
            $query->where('sales_lines.company_id', $companyId);
        }

        return $query->groupBy('sd.seller_id')
            ->toBase()
            ->selectRaw('sd.seller_id as seller_id, sum(sales_lines.net_total) as net')
            ->get()
            ->mapWithKeys(fn ($r) => [(int) $r->seller_id => (float) $r->net])
            ->all();
    }

    /** @return array<string, mixed> */
    private function row(Seller $seller, ?SellerGoal $goal, float $actual, CarbonImmutable $start, CarbonImmutable $end, CarbonImmutable $today, string $state): array
    {
        $dim = $start->daysInMonth;
        $winStart = $seller->starts_on && CarbonImmutable::parse($seller->starts_on)->gt($start) ? CarbonImmutable::parse($seller->starts_on)->startOfDay() : $start;
        $winEnd = $seller->ends_on && CarbonImmutable::parse($seller->ends_on)->lt($end) ? CarbonImmutable::parse($seller->ends_on)->startOfDay() : $end;
        $windowDays = $winStart->gt($winEnd) ? 0 : (int) $winStart->diffInDays($winEnd) + 1;
        $daysOff = $goal ? $goal->days_off : 0;
        $eligible = max(0, $windowDays - $daysOff);

        $amount = $goal ? (float) $goal->amount : null;
        $effective = $amount === null ? null : round($amount * $eligible / $dim, 2);

        // Avance del mes dentro de la ventana del vendedor (las ausencias se asumen repartidas de forma uniforme).
        $elapsed = match (true) {
            $windowDays === 0 || $state === 'future' => 0.0,
            $state === 'closed' || $today->gte($winEnd) => 1.0,
            default => ((int) $winStart->diffInDays($today) + 1) / $windowDays,
        };
        if ($today->lt($winStart)) {
            $elapsed = 0.0;
        }

        $compliance = $effective ? round($actual / $effective * 100, 2) : null;
        $expected = $effective === null ? null : round($effective * $elapsed, 2);
        $pace = $expected ? round($actual / $expected * 100, 2) : null;
        $projected = $state === 'current' && $elapsed > 0 && $effective !== null ? round($actual / $elapsed, 2) : null;

        $status = match (true) {
            $goal === null => 'no_goal',
            $eligible === 0 => 'exempt',
            $compliance >= self::GOAL_PCT => 'met',
            $compliance >= self::WARNING_PCT => 'warning',
            default => 'danger',
        };

        return [
            'seller_id' => $seller->id, 'seller' => $seller->name, 'company_id' => $seller->company_id,
            'starts_on' => $seller->starts_on?->toDateString(), 'ends_on' => $seller->ends_on?->toDateString(),
            'goal_amount' => $amount, 'days_off' => $daysOff, 'source' => $goal?->source,
            'eligible_days' => $eligible, 'days_in_month' => $dim, 'effective_goal' => $effective,
            'actual' => round($actual, 2), 'compliance_pct' => $compliance, 'status' => $status,
            'expected_to_date' => $expected, 'pace_pct' => $pace,
            'projected' => $projected, 'projected_pct' => $projected !== null && $effective ? round($projected / $effective * 100, 2) : null,
            'behind_pace' => $state === 'current' && $elapsed >= self::PACE_MIN_ELAPSED && $elapsed < 1 && $pace !== null && $pace < self::PACE_ALERT_PCT,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $withGoal = array_filter($rows, fn ($r) => $r['effective_goal'] !== null && $r['eligible_days'] > 0);
        $goal = array_sum(array_column($withGoal, 'effective_goal'));
        $actual = array_sum(array_column($withGoal, 'actual'));

        $count = fn (string $s) => count(array_filter($rows, fn ($r) => $r['status'] === $s));

        return [
            'effective_goal' => round($goal, 2), 'actual' => round($actual, 2),
            'compliance_pct' => $goal > 0 ? round($actual / $goal * 100, 2) : null,
            'sellers' => count($rows), 'with_goal' => count($withGoal),
            'met' => $count('met'), 'warning' => $count('warning'), 'danger' => $count('danger'), 'no_goal' => $count('no_goal'),
            'behind_pace' => count(array_filter($rows, fn ($r) => $r['behind_pace'])),
            'note' => 'Solo suma vendedores con meta y días elegibles.',
        ];
    }
}
