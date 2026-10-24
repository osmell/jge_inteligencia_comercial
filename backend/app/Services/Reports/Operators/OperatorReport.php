<?php

declare(strict_types=1);

namespace App\Services\Reports\Operators;

use App\Models\Operator;
use App\Services\Reports\Reconciliation;
use App\Services\Reports\ReportPeriod;
use App\Services\Reports\SalesFigures;
use App\Services\Reports\Semaphore;

/**
 * Reporte de evaluación de operadores (quienes facturan): producción y control de captura, cada indicador con su semáforo,
 * más un total y el contraste contra la venta consolidada.
 */
class OperatorReport
{
    public function __construct(
        private readonly SalesFigures $sales,
        private readonly OperatorControlFigures $control,
        private readonly Semaphore $semaphore,
        private readonly Reconciliation $reconciliation,
    ) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function build(?array $companyIds, ReportPeriod $period, array $filters): array
    {
        $current = $this->sales->forPeriod($companyIds, $period->from, $period->to, $filters, 'operator');
        $previous = $this->sales->forPeriod($companyIds, $period->compareFrom, $period->compareTo, $filters, 'operator');
        $control = $this->control->forPeriod($companyIds, $period->from, $period->to, $filters);
        $branchFiltered = isset($filters['branch_id']);

        $operators = Operator::query()->with('company:id,name')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', (array) $companyIds))
            ->get()
            ->filter(fn (Operator $o) => $branchFiltered ? (isset($current[$o->id]) || isset($previous[$o->id])) : ($o->is_active || isset($current[$o->id]) || isset($previous[$o->id])))
            ->values();

        $totalNet = array_sum(array_column($current, 'net_total'));
        $rows = [];
        foreach ($operators as $operator) {
            $s = $current[$operator->id] ?? $this->emptySales();
            $before = $previous[$operator->id] ?? ['net_total' => 0.0, 'documents' => 0];
            $s['previous_net_total'] = (float) $before['net_total'];
            $s['growth_pct'] = $before['net_total'] > 0 ? round(($s['net_total'] - $before['net_total']) / $before['net_total'] * 100, 2) : null;
            $s['previous_documents'] = (int) $before['documents'];
            $s['documents_growth_pct'] = $before['documents'] > 0 ? round(($s['documents'] - $before['documents']) / $before['documents'] * 100, 2) : null;
            $s['share_pct'] = $totalNet > 0 ? round($s['net_total'] / $totalNet * 100, 2) : null;

            $c = $control[$operator->id] ?? ['lines' => 0, 'active_days' => 0, 'timed_documents' => 0, 'high_discount_lines' => 0, 'zero_price_lines' => 0, 'direct_net' => 0.0, 'atypical_documents' => 0, 'negative_documents' => 0, 'atypical_evaluated' => false];
            $documents = $s['documents'];
            $production = [
                'lines' => $c['lines'],
                'lines_per_document' => $documents > 0 ? round($c['lines'] / $documents, 2) : null,
                'active_days' => $c['active_days'],
                'documents_per_active_day' => $c['active_days'] > 0 ? round($documents / $c['active_days'], 2) : null,
                'timed_documents_pct' => $documents > 0 ? round($c['timed_documents'] / $documents * 100, 1) : null,
            ];
            $quality = [
                'high_discount_lines' => $c['high_discount_lines'],
                'high_discount_lines_pct' => $c['lines'] > 0 ? round($c['high_discount_lines'] / $c['lines'] * 100, 2) : null,
                'zero_price_lines' => $c['zero_price_lines'],
                'zero_price_lines_pct' => $c['lines'] > 0 ? round($c['zero_price_lines'] / $c['lines'] * 100, 2) : null,
                'atypical_documents' => $c['atypical_documents'],
                'atypical_documents_pct' => $c['atypical_evaluated'] && $documents > 0 ? round($c['atypical_documents'] / $documents * 100, 2) : null,
                'negative_documents' => $c['negative_documents'],
                'direct_sales_pct' => $s['net_total'] > 0 ? round($c['direct_net'] / $s['net_total'] * 100, 2) : null,
            ];

            $rows[] = [
                'operator' => ['id' => $operator->id, 'name' => $operator->name, 'company_id' => $operator->company_id, 'company' => $operator->company?->name, 'is_active' => $operator->is_active],
                'sales' => $s,
                'production' => $production,
                'control' => $quality,
                'semaphores' => [
                    'growth_pct' => $this->semaphore->level('growth_pct', $s['growth_pct']),
                    'discount_pct' => $this->semaphore->level('discount_pct', $s['discount_pct']),
                    'below_list_pct' => $this->semaphore->level('below_list_pct', $s['below_list_pct']),
                    'high_discount_lines_pct' => $this->semaphore->level('high_discount_lines_pct', $quality['high_discount_lines_pct']),
                    'zero_price_lines_pct' => $this->semaphore->level('zero_price_lines_pct', $quality['zero_price_lines_pct']),
                    'atypical_documents_pct' => $this->semaphore->level('atypical_documents_pct', $quality['atypical_documents_pct']),
                ],
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['sales']['net_total'], $a['operator']['name']] <=> [$a['sales']['net_total'], $b['operator']['name']]);
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
            'reconciliation' => $this->reconciliation->between($companyIds, $period, $filters, $net, $docs),
            'notes' => $period->isUnevenComparison() ? ['no_operator_cancellations', 'uneven_comparison'] : ['no_operator_cancellations'],
        ];
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
        $lines = array_sum(array_column(array_column($rows, 'production'), 'lines'));
        $sum = fn (string $key) => array_sum(array_column(array_column($rows, 'control'), $key));

        return [
            'operators' => count($rows),
            'net_total' => round($net, 2),
            'previous_net_total' => round($before, 2),
            'growth_pct' => $before > 0 ? round(($net - $before) / $before * 100, 2) : null,
            'documents' => $documents,
            'lines' => $lines,
            'average_ticket' => $documents > 0 ? round($net / $documents, 2) : null,
            'high_discount_lines' => $sum('high_discount_lines'),
            'zero_price_lines' => $sum('zero_price_lines'),
            'atypical_documents' => $sum('atypical_documents'),
            'negative_documents' => $sum('negative_documents'),
            'red_flags' => array_sum(array_map(fn (array $r) => count(array_filter($r['semaphores'], fn (string $l) => $l === Semaphore::BAD)), $rows)),
        ];
    }
}
