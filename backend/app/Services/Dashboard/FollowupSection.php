<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\FollowUpAgenda;
use Carbon\CarbonImmutable;

/** Sección «followup» del dashboard inicial. */
final class FollowupSection extends DashboardSection
{
    public function __construct(private readonly FollowUpAgenda $agendaService) {}

    public function key(): string
    {
        return 'followup';
    }

    public function permission(): string
    {
        return 'interactions.manage';
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->agenda($context->user, $context->today);
    }

    /** @return array<string, mixed> */
    private function agenda(User $user, CarbonImmutable $today): array
    {
        $days = 30;
        $a = $this->agendaService->build($user, ['horizon_days' => 0, 'no_contact_days' => $days], CarbonImmutable::now());

        return [
            'overdue' => $a['meta']['overdue_total'], 'today' => $a['meta']['upcoming_total'], 'date' => $today->toDateString(),
            'actions' => array_map(fn (array $x) => ['customer' => ['company_id' => $x['customer']['company_id'], 'code' => $x['customer']['code'], 'name' => $x['customer']['name']], 'next_action' => $x['next_action'], 'next_action_at' => $x['next_action_at'], 'days_overdue' => $x['days_overdue']],
                array_slice(array_merge($a['overdue'], $a['upcoming']), 0, 5)),
            'no_contact' => [
                'count' => $a['meta']['reactivate_total'], 'days' => $days,
                'top' => array_map(fn (array $r) => ['code' => $r['code'], 'company_id' => $r['company_id'], 'name' => $r['name'], 'status' => $r['status'], 'days_since_last_purchase' => $r['days_since_last_purchase'], 'seller' => $r['seller']], array_slice($a['reactivate'], 0, 5)),
            ],
        ];
    }
}
