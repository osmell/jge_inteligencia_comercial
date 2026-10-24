<?php

declare(strict_types=1);

namespace App\Services\Reports\Branches;

use App\Models\Branch;
use App\Services\Reports\CreditFigures;
use App\Services\Reports\PortfolioFigures;
use App\Services\Reports\Reconciliation;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SalesFigures;
use App\Services\Reports\Semaphore;
use Carbon\CarbonImmutable;

/**
 * Reporte de evaluación de sucursales: resultado y crecimiento, clientes, dependencia de una persona o de pocos clientes, cartera,
 * cobranza y existencias, cada indicador con su semáforo, más un total y el contraste contra la venta consolidada.
 * Una persona ve las sucursales que tiene asignadas (el global scope de Branch); el resto de los reportes ven toda la compañía.
 */
class BranchReport
{
    public function __construct(
        private readonly SalesFigures $sales,
        private readonly BranchSellerFigures $dependency,
        private readonly PortfolioFigures $portfolio,
        private readonly CreditFigures $credit,
        private readonly BranchInventoryFigures $inventory,
        private readonly Semaphore $semaphore,
        private readonly Reconciliation $reconciliation,
    ) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters  filtros de venta (sucursal)
     * @return array<string, mixed>
     */
    public function build(?array $companyIds, ReportPeriod $period, array $filters, ?CarbonImmutable $now = null): array
    {
        $today = ($now ?? CarbonImmutable::now())->startOfDay();
        $allowed = Branch::query()->with('company:id,name')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', (array) $companyIds))
            ->when(isset($filters['branch_id']), fn ($q) => $q->whereKey((int) $filters['branch_id']))
            ->get();
        $scoped = $filters + ['branch_ids' => $allowed->modelKeys()];

        $current = $this->sales->forPeriod($companyIds, $period->from, $period->to, $scoped, 'branch');
        $previous = $this->sales->forPeriod($companyIds, $period->compareFrom, $period->compareTo, $scoped, 'branch');
        $branches = $allowed->filter(fn (Branch $b) => $b->is_active || isset($current[$b->id]) || isset($previous[$b->id]))->values();

        $dependency = $this->dependency->forPeriod($companyIds, $period->from, $period->to, $scoped);
        $portfolio = $this->portfolio->snapshot($companyIds, $today, 'branch');
        $credit = $this->credit->snapshot($companyIds, $today, 'branch');
        $inventory = $this->inventory->snapshot($companyIds, array_values(array_map('intval', $branches->modelKeys())), $today);

        $totalNet = array_sum(array_column($current, 'net_total'));
        $rows = [];
        foreach ($branches as $branch) {
            $s = $current[$branch->id] ?? $this->emptySales();
            $before = $previous[$branch->id] ?? ['net_total' => 0.0, 'documents' => 0];
            $s['previous_net_total'] = (float) $before['net_total'];
            $s['growth_pct'] = $before['net_total'] > 0 ? round(($s['net_total'] - $before['net_total']) / $before['net_total'] * 100, 2) : null;
            $s['previous_documents'] = (int) $before['documents'];
            $s['documents_growth_pct'] = $before['documents'] > 0 ? round(($s['documents'] - $before['documents']) / $before['documents'] * 100, 2) : null;
            $s['share_pct'] = $totalNet > 0 ? round($s['net_total'] / $totalNet * 100, 2) : null;

            $dep = $dependency[$branch->id] ?? ['sellers_count' => 0, 'top_seller' => null, 'top_seller_pct' => null];
            $pf = $portfolio[$branch->id] ?? ['total' => 0, 'active' => 0, 'new' => 0, 'recovered' => 0, 'inactive' => 0, 'lost' => 0, 'lost_pct' => null];
            $cr = $credit[$branch->id] ?? ['balance' => 0.0, 'overdue' => 0.0, 'overdue_pct' => null];
            $inv = $inventory[$branch->id];

            $rows[] = [
                'branch' => ['id' => $branch->id, 'name' => $branch->name, 'code' => $branch->code, 'company_id' => $branch->company_id, 'company' => $branch->company->name, 'is_active' => $branch->is_active],
                'sales' => $s,
                'dependency' => $dep,
                'portfolio' => $pf,
                'credit' => $cr,
                'inventory' => $inv,
                'semaphores' => [
                    'growth_pct' => $this->semaphore->level('growth_pct', $s['growth_pct']),
                    'discount_pct' => $this->semaphore->level('discount_pct', $s['discount_pct']),
                    'below_list_pct' => $this->semaphore->level('below_list_pct', $s['below_list_pct']),
                    'top3_concentration_pct' => $this->semaphore->level('top3_concentration_pct', $s['top3_concentration_pct']),
                    'top_seller_dependency_pct' => $this->semaphore->level('top_seller_dependency_pct', $dep['top_seller_pct']),
                    'lost_customers_pct' => $this->semaphore->level('lost_customers_pct', $pf['lost_pct']),
                    'overdue_pct' => $this->semaphore->level('overdue_pct', $cr['overdue_pct']),
                    'no_rotation_pct' => $this->semaphore->level('no_rotation_pct', $inv['no_rotation_pct']),
                    'stockout_demand_pct' => $this->semaphore->level('stockout_demand_pct', $inv['stockout_demand_pct']),
                    'open_shortfalls' => $this->semaphore->level('open_shortfalls', (float) $inv['open_shortfalls']),
                ],
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['sales']['net_total'], $a['branch']['name']] <=> [$a['sales']['net_total'], $b['branch']['name']]);
        foreach ($rows as $i => &$row) {
            $row['rank'] = $row['sales']['net_total'] > 0 ? $i + 1 : null;
        }
        unset($row);

        $net = array_sum(array_column(array_column($rows, 'sales'), 'net_total'));
        $docs = array_sum(array_column(array_column($rows, 'sales'), 'documents'));

        return [
            ...$period->toArray(),
            'rows' => $rows,
            'totals' => $this->totals($rows),
            'reconciliation' => $this->reconciliation->between($companyIds, $period, $scoped, $net, $docs),
            'notes' => $period->isUnevenComparison() ? ['portfolio_credit_inventory_as_of_today', 'uneven_comparison'] : ['portfolio_credit_inventory_as_of_today'],
        ];
    }

    /**
     * Sucursales que la persona puede evaluar (las asignadas, dentro de las compañías pedidas y, si se eligió una, solo esa).
     *
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return list<int>
     */
    public function visibleBranchIds(?array $companyIds, array $filters): array
    {
        return array_values(array_map('intval', Branch::query()
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', (array) $companyIds))
            ->when(isset($filters['branch_id']), fn ($q) => $q->whereKey((int) $filters['branch_id']))
            ->pluck('id')->all()));
    }

    /** @return array<string, mixed> */
    private function emptySales(): array
    {
        return ['net_total' => 0.0, 'documents' => 0, 'customers' => 0, 'average_ticket' => null, 'discount_pct' => null, 'below_list_pct' => null, 'categories_per_customer' => null, 'top3_concentration_pct' => null];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function totals(array $rows): array
    {
        $net = array_sum(array_column(array_column($rows, 'sales'), 'net_total'));
        $before = array_sum(array_column(array_column($rows, 'sales'), 'previous_net_total'));
        $documents = array_sum(array_column(array_column($rows, 'sales'), 'documents'));
        $sum = fn (string $group, string $key) => array_sum(array_column(array_column($rows, $group), $key));

        return [
            'branches' => count($rows),
            'net_total' => round($net, 2),
            'previous_net_total' => round($before, 2),
            'growth_pct' => $before > 0 ? round(($net - $before) / $before * 100, 2) : null,
            'documents' => $documents,
            'average_ticket' => $documents > 0 ? round($net / $documents, 2) : null,
            'credit_balance' => round($sum('credit', 'balance'), 2),
            'credit_overdue' => round($sum('credit', 'overdue'), 2),
            'no_rotation' => $sum('inventory', 'no_rotation'),
            'stockout_with_demand' => $sum('inventory', 'stockout_with_demand'),
            'open_shortfalls' => $sum('inventory', 'open_shortfalls'),
            'red_flags' => array_sum(array_map(fn (array $r) => count(array_filter($r['semaphores'], fn (string $l) => $l === Semaphore::BAD)), $rows)),
        ];
    }
}
