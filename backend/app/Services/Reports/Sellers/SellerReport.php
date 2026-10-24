<?php

declare(strict_types=1);

namespace App\Services\Reports\Sellers;

use App\Models\Seller;
use App\Services\Reports\Reconciliation;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SalesFigures;
use App\Services\Reports\Semaphore;
use Carbon\CarbonImmutable;

/**
 * Reporte de evaluación de vendedores: una fila por vendedor con su resultado de ventas, cumplimiento de meta, disciplina de precio,
 * cartera, cobranza y seguimiento, cada indicador con su semáforo, más un total y el contraste contra la venta consolidada.
 */
class SellerReport
{
    public function __construct(
        private readonly SalesFigures $sales,
        private readonly SellerGoalFigures $goals,
        private readonly SellerPortfolioFigures $portfolio,
        private readonly SellerCreditFigures $credit,
        private readonly SellerFollowUpFigures $followUp,
        private readonly Semaphore $semaphore,
        private readonly Reconciliation $reconciliation,
    ) {}

    /**
     * @param  list<int>|null  $companyIds  compañías a evaluar (null = todas)
     * @param  array<string, mixed>  $filters  filtros de venta (sucursal)
     * @return array<string, mixed>
     */
    public function build(?array $companyIds, ReportPeriod $period, array $filters, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $today = $now->startOfDay();
        $current = $this->sales->forPeriod($companyIds, $period->from, $period->to, $filters);
        $previous = $this->sales->forPeriod($companyIds, $period->compareFrom, $period->compareTo, $filters);
        $branchFiltered = isset($filters['branch_id']);

        $sellers = Seller::query()->with(['company:id,name', 'branch:id,name'])
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', (array) $companyIds))
            ->get()
            // Con una sucursal elegida solo cuentan los vendedores que vendieron en ella; sin ella, los activos y los que vendieron.
            ->filter(fn (Seller $s) => $branchFiltered ? (isset($current[$s->id]) || isset($previous[$s->id])) : ($s->is_active || isset($current[$s->id]) || isset($previous[$s->id])))
            ->values();

        $actual = array_map(fn (array $s) => $s['net_total'], $current);
        // La meta es del vendedor en toda la compañía: con una sucursal elegida la venta es parcial y compararla con la meta completa engaña.
        $goals = $branchFiltered ? [] : $this->goals->forPeriod($sellers, $period->from, $period->to, $actual, $today);
        $portfolio = $this->portfolio->snapshot($companyIds, $today);
        $credit = $this->credit->snapshot($companyIds, $today);
        $followUp = $this->followUp->forPeriod($sellers, $companyIds, $period->from, $period->to, $now);

        $totalNet = array_sum($actual);
        $rows = [];
        foreach ($sellers as $seller) {
            $s = $current[$seller->id] ?? $this->emptySales();
            $before = $previous[$seller->id]['net_total'] ?? 0.0;
            $s['previous_net_total'] = $before;
            $s['growth_pct'] = $before > 0 ? round(($s['net_total'] - $before) / $before * 100, 2) : null;
            $s['share_pct'] = $totalNet > 0 ? round($s['net_total'] / $totalNet * 100, 2) : null;

            $goal = $goals[$seller->id] ?? ['goal' => null, 'attainment_pct' => null, 'projected_pct' => null, 'status' => Semaphore::NONE];
            $pf = $portfolio[$seller->id] ?? ['total' => 0, 'active' => 0, 'new' => 0, 'recovered' => 0, 'inactive' => 0, 'lost' => 0, 'lost_pct' => null];
            $cr = $credit[$seller->id] ?? ['balance' => 0.0, 'overdue' => 0.0, 'overdue_pct' => null];
            $fu = $followUp[$seller->id];

            $rows[] = [
                'seller' => [
                    'id' => $seller->id, 'name' => $seller->name, 'company_id' => $seller->company_id, 'company' => $seller->company?->name,
                    'branch_id' => $seller->branch_id, 'branch' => $seller->branch?->name, 'is_active' => $seller->is_active,
                ],
                'sales' => $s,
                'goal' => $goal,
                'portfolio' => $pf,
                'credit' => $cr,
                'follow_up' => $fu,
                'semaphores' => [
                    'goal_attainment' => $goal['status'],
                    'growth_pct' => $this->semaphore->level('growth_pct', $s['growth_pct']),
                    'discount_pct' => $this->semaphore->level('discount_pct', $s['discount_pct']),
                    'below_list_pct' => $this->semaphore->level('below_list_pct', $s['below_list_pct']),
                    'top3_concentration_pct' => $this->semaphore->level('top3_concentration_pct', $s['top3_concentration_pct']),
                    'lost_customers_pct' => $this->semaphore->level('lost_customers_pct', $pf['lost_pct']),
                    'overdue_pct' => $this->semaphore->level('overdue_pct', $cr['overdue_pct']),
                    'quote_conversion_pct' => $this->semaphore->level('quote_conversion_pct', $fu['quote_conversion_pct']),
                    'overdue_follow_ups' => $this->semaphore->level('overdue_follow_ups', $fu['overdue_follow_ups'] === null ? null : (float) $fu['overdue_follow_ups']),
                ],
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['sales']['net_total'], $a['seller']['name']] <=> [$a['sales']['net_total'], $b['seller']['name']]);
        foreach ($rows as $i => &$row) {
            $row['rank'] = $row['sales']['net_total'] > 0 ? $i + 1 : null;
        }
        unset($row);

        return [
            ...$period->toArray(),
            'rows' => $rows,
            'totals' => $this->totals($rows, $previous),
            'reconciliation' => $this->reconciliation->between(
                $companyIds, $period, $filters,
                array_sum(array_column(array_column($rows, 'sales'), 'net_total')), array_sum(array_column(array_column($rows, 'sales'), 'documents')),
            ),
            'notes' => array_values(array_filter([
                'portfolio_and_credit_as_of_today',
                $branchFiltered ? 'goal_not_comparable_with_branch_filter' : null,
                $period->isUnevenComparison() ? 'uneven_comparison' : null,
            ])),
        ];
    }

    /** @return array<string, mixed> */
    private function emptySales(): array
    {
        return ['net_total' => 0.0, 'documents' => 0, 'customers' => 0, 'average_ticket' => null, 'discount_pct' => null, 'below_list_pct' => null, 'categories_per_customer' => null, 'top3_concentration_pct' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array<string, mixed>>  $previous
     * @return array<string, mixed>
     */
    private function totals(array $rows, array $previous): array
    {
        $net = array_sum(array_column(array_column($rows, 'sales'), 'net_total'));
        $documents = array_sum(array_column(array_column($rows, 'sales'), 'documents'));
        $before = array_sum(array_column(array_column($rows, 'sales'), 'previous_net_total'));
        $rated = array_filter($rows, fn (array $r) => $r['goal']['attainment_pct'] !== null);
        $goal = array_sum(array_column(array_column($rated, 'goal'), 'goal'));
        $ratedNet = array_sum(array_column(array_column($rated, 'sales'), 'net_total'));

        return [
            'sellers' => count($rows),
            'net_total' => round($net, 2),
            'previous_net_total' => round($before, 2),
            'growth_pct' => $before > 0 ? round(($net - $before) / $before * 100, 2) : null,
            'documents' => $documents,
            'average_ticket' => $documents > 0 ? round($net / $documents, 2) : null,
            'goal' => round($goal, 2),
            'attainment_pct' => $goal > 0 ? round($ratedNet / $goal * 100, 2) : null,
            'sellers_with_goal' => count($rated),
            'credit_balance' => round(array_sum(array_column(array_column($rows, 'credit'), 'balance')), 2),
            'credit_overdue' => round(array_sum(array_column(array_column($rows, 'credit'), 'overdue')), 2),
            'red_flags' => array_sum(array_map(fn (array $r) => count(array_filter($r['semaphores'], fn (string $l) => $l === Semaphore::BAD)), $rows)),
        ];
    }
}
