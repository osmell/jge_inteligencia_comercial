<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\SellerGoals;
use Carbon\CarbonImmutable;

/** Sección «goals» del dashboard inicial. */
final class GoalsSection extends DashboardSection
{
    public function __construct(private readonly SellerGoals $goals) {}

    public function key(): string
    {
        return 'goals';
    }

    public function permission(): string
    {
        return 'sales.view';
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->progress($context->user, $context->sellerIds, $context->window['to'], $context->companyId, $context->today);
    }

    /**
     * @param  list<int>|null  $sellerIds
     * @return array<string, mixed>
     */
    private function progress(User $user, ?array $sellerIds, CarbonImmutable $monthOf, ?int $companyId, CarbonImmutable $today): array
    {
        $progress = $this->goals->progress($user, $monthOf->format('Y-m'), $companyId, $today);
        $rows = array_values(array_filter($progress['rows'], fn (array $r) => $sellerIds === null || in_array($r['seller_id'], $sellerIds, true)));
        $withGoal = array_values(array_filter($rows, fn (array $r) => ! in_array($r['status'], ['no_goal', 'exempt'], true)));
        $goal = array_sum(array_column($withGoal, 'effective_goal'));
        $actual = array_sum(array_column($withGoal, 'actual'));
        $projected = array_sum(array_map(fn (array $r) => $r['projected'] ?? $r['actual'], $withGoal));
        // Días que quedan del mes (hoy incluido) y lo que habría que vender por día para llegar a la meta.
        $daysLeft = $progress['state'] === 'current' ? $today->daysInMonth - $today->day + 1 : 0;
        $remaining = max(0, $goal - $actual);

        return [
            'month' => $progress['month'], 'state' => $progress['state'],
            'totals' => ['goal' => round($goal, 2), 'actual' => round($actual, 2), 'remaining' => round(max(0, $goal - $actual), 2), 'compliance_pct' => $goal > 0 ? round($actual / $goal * 100, 2) : null,
                'projected' => $progress['state'] === 'current' ? round($projected, 2) : null, 'projected_pct' => $progress['state'] === 'current' && $goal > 0 ? round($projected / $goal * 100, 2) : null,
                'days_left' => $daysLeft, 'needed_per_day' => $daysLeft > 0 && $remaining > 0 ? round($remaining / $daysLeft, 2) : null, 'sellers_with_goal' => count($withGoal), 'met' => count(array_filter($withGoal, fn (array $r) => $r['status'] === 'met'))],
            'rows' => array_map(fn (array $r) => array_intersect_key($r, array_flip(['seller_id', 'seller', 'effective_goal', 'actual', 'compliance_pct', 'projected', 'projected_pct', 'status', 'behind_pace'])), $rows),
        ];
    }
}
