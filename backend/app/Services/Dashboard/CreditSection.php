<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\CreditInvoice;
use App\Services\CreditPortfolio;
use App\Services\PaymentPromises;
use Carbon\CarbonImmutable;

/** Sección «credit» del dashboard inicial. */
final class CreditSection extends DashboardSection
{
    public function __construct(private readonly CreditPortfolio $credit, private readonly PaymentPromises $promises) {}

    public function key(): string
    {
        return 'credit';
    }

    public function permission(): string
    {
        return 'credit.view';
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->portfolio($context->sellerIds, $context->companyId, $context->today);
    }

    /**
     * @param  list<int>|null  $sellerIds
     * @return array<string, mixed>
     */
    private function portfolio(?array $sellerIds, ?int $companyId, CarbonImmutable $today): array
    {
        $aging = $this->credit->aging($companyId, null, $today, $sellerIds);
        $total = (float) $aging['totals']['total'];
        $current = (float) $aging['totals']['current'];
        $buckets = array_map(fn (string $b) => ['bucket' => $b, 'amount' => $aging['totals'][$b]], $aging['buckets']);
        $invoices = array_sum(array_column($aging['customers'], 'invoices'));

        $scoped = $sellerIds === null ? null : CreditInvoice::query()->whereIn('seller_id', $sellerIds)->toBase()->select('company_id', 'customer_code')->distinct()->get()->map(fn ($r) => $r->company_id.'|'.$r->customer_code)->flip();
        $rows = array_filter($this->promises->list(null, ['status' => 'all', 'company_id' => $companyId], $today)['rows'], fn (array $p) => $scoped === null || isset($scoped[$p['company_id'].'|'.$p['customer']['code']]));
        $soon = $today->addDays(7)->toDateString();
        $open = array_filter($rows, fn (array $p) => $p['status'] === PaymentPromises::OPEN && $p['promised_on'] <= $soon);
        $broken = array_filter($rows, fn (array $p) => $p['status'] === PaymentPromises::BROKEN && $p['days_overdue'] <= 30);
        $attention = array_map(fn (array $p) => ['company_id' => $p['company_id'], 'code' => $p['customer']['code'], 'name' => $p['customer']['name'], 'promised_on' => $p['promised_on'], 'amount' => $p['amount'], 'status' => $p['status'], 'days_overdue' => $p['days_overdue']],
            array_slice(array_merge($broken, $open), 0, 5));

        return [
            'total' => $aging['totals']['total'], 'overdue' => number_format($total - $current, 2, '.', ''), 'overdue_pct' => $total > 0 ? round(($total - $current) / $total * 100, 1) : null,
            'invoices' => $invoices, 'customers' => count($aging['customers']), 'buckets' => $buckets,
            'top_debtors' => array_map(fn (array $c) => ['company_id' => $c['company_id'], 'code' => $c['code'], 'name' => $c['name'], 'total' => $c['total'], 'oldest_days' => $c['oldest_days']], array_slice($aging['customers'], 0, 5)),
            'promises' => ['due_next_7_days' => count($open), 'due_amount' => number_format(array_sum(array_map(fn (array $p) => (float) $p['amount'], $open)), 2, '.', ''), 'broken_30_days' => count($broken), 'attention' => $attention],
        ];
    }
}
