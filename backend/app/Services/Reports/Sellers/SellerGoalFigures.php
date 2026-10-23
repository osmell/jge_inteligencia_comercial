<?php

declare(strict_types=1);

namespace App\Services\Reports\Sellers;

use App\Models\Seller;
use App\Models\SellerGoal;
use App\Services\Reports\Semaphore;
use App\Services\SellerGoals;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Cumplimiento de la meta de cada vendedor en un periodo cualquiera. La meta de cada mes se prorratea por los días del vendedor
 * (alta, baja y ausencias, igual que en la pantalla de Metas) que caen dentro del periodo. Si algún mes del periodo en que el
 * vendedor trabajó no tiene meta capturada, NO se califica: una meta incompleta daría un porcentaje engañoso.
 */
class SellerGoalFigures
{
    /** Con menos de esta parte del periodo transcurrida no se proyecta el cierre (el ritmo de los primeros días no es representativo). */
    private const MIN_ELAPSED_FOR_PROJECTION = 0.2;

    public function __construct(private readonly SellerGoals $goals, private readonly Semaphore $semaphore) {}

    /**
     * @param  Collection<int, Seller>  $sellers
     * @param  array<int, float>  $actualBySeller  venta neta del periodo por vendedor
     * @return array<int, array<string, mixed>> vendedor → meta del periodo y cumplimiento
     */
    public function forPeriod(Collection $sellers, CarbonImmutable $from, CarbonImmutable $to, array $actualBySeller, CarbonImmutable $today): array
    {
        $months = [];
        for ($m = $from->startOfMonth(); $m <= $to; $m = $m->addMonth()) {
            $months[] = $m;
        }
        $goals = SellerGoal::query()->whereIn('seller_id', $sellers->pluck('id')->all())
            ->whereBetween('month', [$from->startOfMonth()->toDateString(), $to->startOfMonth()->toDateString()])->get()
            ->groupBy('seller_id')->map(fn (Collection $g) => $g->keyBy(fn (SellerGoal $x): string => CarbonImmutable::parse($x->month)->format('Y-m')));

        $out = [];
        foreach ($sellers as $seller) {
            $goal = 0.0;
            $complete = true;
            $hasAny = false;
            foreach ($months as $start) {
                $row = $goals->get($seller->id)?->get($start->format('Y-m'));
                $window = $this->goals->window($seller, $row, $start, $start->endOfMonth()->startOfDay());
                $overlap = $this->overlapDays($window['start'], $window['end'], $from, $to);
                if ($overlap === 0) {
                    continue;   // el vendedor no trabajó ese mes dentro del periodo
                }
                if ($row === null) {
                    $complete = false;

                    continue;
                }
                $hasAny = true;
                $goal += (float) $row->amount * $window['eligible'] / $start->daysInMonth * $overlap / $window['days'];
            }

            $out[$seller->id] = $this->figures($goal, $complete && $hasAny, (float) ($actualBySeller[$seller->id] ?? 0), $from, $to, $today);
        }

        return $out;
    }

    private function overlapDays(CarbonImmutable $a, CarbonImmutable $b, CarbonImmutable $from, CarbonImmutable $to): int
    {
        $start = $a->max($from);
        $end = $b->min($to);

        return $start->gt($end) ? 0 : (int) $start->diffInDays($end) + 1;
    }

    /** @return array<string, mixed> */
    private function figures(float $goal, bool $rated, float $actual, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $today): array
    {
        if (! $rated || $goal <= 0) {
            return ['goal' => $rated ? round($goal, 2) : null, 'attainment_pct' => null, 'projected_pct' => null, 'status' => Semaphore::NONE];
        }

        $attainment = round($actual / $goal * 100, 2);
        $days = (int) $from->diffInDays($to) + 1;
        $elapsed = $today->gte($from) && $today->lt($to) ? ((int) $from->diffInDays($today) + 1) / $days : null;
        $projected = $elapsed !== null && $elapsed >= self::MIN_ELAPSED_FOR_PROJECTION ? round($actual / $elapsed / $goal * 100, 2) : null;

        return ['goal' => round($goal, 2), 'attainment_pct' => $attainment, 'projected_pct' => $projected, 'status' => $this->semaphore->level('goal_attainment', $attainment)];
    }
}
