<?php

namespace App\Automations;

use App\Services\FollowUpAgenda;
use App\Services\Notifier;
use Carbon\CarbonImmutable;

/** A cada persona con seguimiento, un resumen diario de las acciones de su agenda que vencieron o vencen hoy (en su alcance). */
class FollowupDueHandler extends NoticeHandler
{
    public function __construct(Notifier $notifier, private readonly FollowUpAgenda $agenda)
    {
        parent::__construct($notifier);
    }

    protected function notices(array $params): void
    {
        $now = CarbonImmutable::now();
        foreach ($this->notifier->usersWith('interactions.manage') as $user) {
            $counts = $this->notifier->scopedAs($user, fn () => $this->agenda->dueCounts($user, $now));
            if ($counts['overdue'] + $counts['today'] === 0) {
                continue;
            }
            $parts = array_filter([$counts['overdue'] > 0 ? $this->plural($counts['overdue'], 'acción vencida', 'acciones vencidas') : null, $counts['today'] > 0 ? $this->plural($counts['today'], 'acción para hoy', 'acciones para hoy') : null]);
            $this->notify($user, 'followup_due', 'Tienes '.implode(' y ', $parts).' en tu agenda', 'Revisa tu agenda de seguimiento.', '/agenda', 'followup_due:'.$now->toDateString(), $counts['overdue'] > 0 ? 'warning' : 'info');
        }
    }
}
