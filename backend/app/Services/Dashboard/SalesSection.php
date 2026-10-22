<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Services\SalesStatistics;
use Carbon\CarbonImmutable;

/** Sección «sales» del dashboard inicial. */
final class SalesSection extends DashboardSection
{
    public function __construct(private readonly SalesStatistics $sales) {}

    public function key(): string
    {
        return 'sales';
    }

    public function permission(): string
    {
        return 'sales.view';
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->summary($context->window, $context->filters);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function summary(array $w, array $filters): array
    {
        $summary = $this->sales->summary($w['from'], $w['to'], $filters, $w['previous']);
        $yearAgo = $w['year_ago'] === null ? null : $this->sales->summary($w['from'], $w['to'], $filters, $w['year_ago']);
        $cur = $summary['current'];
        $block = fn (array $kpis, array $range, ?float $change) => ['from' => $range[0]->toDateString(), 'to' => $range[1]->toDateString(), 'net_total' => $kpis['net_total'], 'documents' => $kpis['documents'], 'change_pct' => $change];
        $first = $this->sales->overall($filters)['first'];

        return [
            'period' => ['from' => $w['from']->toDateString(), 'to' => $w['to']->toDateString(), 'label' => $w['label']],
            'current' => ['net_total' => $cur['net_total'], 'documents' => $cur['documents'], 'customers' => $cur['customers'], 'average_ticket' => $cur['average_ticket']],
            'previous' => $block($summary['previous'], $w['previous'], $summary['change_pct']['net_total'] ?? null),
            'year_ago' => $yearAgo === null ? null : $block($yearAgo['previous'], $w['year_ago'], $yearAgo['change_pct']['net_total'] ?? null),
            'daily' => $this->series('day', $w['from'], $w['to'], $filters, 'date'),
            'daily_previous' => $this->series('day', $w['previous'][0], $w['previous'][1], $filters, 'date'),
            'monthly' => $this->series('month', $w['to']->subMonthsNoOverflow(11)->startOfMonth(), $w['to'], $filters, 'month'),
            'history_since' => $first,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    private function series(string $dimension, CarbonImmutable $from, CarbonImmutable $to, array $filters, string $name): array
    {
        return array_map(fn (array $r) => [$name => substr((string) $r['key'], 0, $dimension === 'month' ? 7 : 10), 'net_total' => $r['net_total'], 'documents' => $r['documents']],
            $this->sales->breakdown($dimension, $from, $to, $filters, 'net_total', 400)['rows']);
    }
}
