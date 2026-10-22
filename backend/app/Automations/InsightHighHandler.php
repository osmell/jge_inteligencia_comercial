<?php

declare(strict_types=1);

namespace App\Automations;

use App\Models\InsightAlert;
use Carbon\CarbonImmutable;

/** A cada persona con acceso a la inteligencia comercial, un resumen de las recomendaciones graves nuevas de los clientes que ve. */
class InsightHighHandler extends NoticeHandler
{
    private const NEW_WITHIN_DAYS = 2;

    protected function notices(array $params): void
    {
        $now = CarbonImmutable::now();
        foreach ($this->notifier->usersWith('insights.view') as $user) {
            $alerts = $this->notifier->scopedAs($user, fn () => InsightAlert::query()->visibleCustomers()->where(['status' => InsightAlert::OPEN, 'severity' => 'high'])
                ->where('first_detected_at', '>=', $now->subDays(self::NEW_WITHIN_DAYS))->orderBy('customer_name')->get(['customer_name', 'type']));
            if ($alerts->isEmpty()) {
                continue;
            }
            $names = $alerts->pluck('customer_name')->unique()->take(3)->implode(', ');
            $this->notify($user, 'insight_high', $this->plural($alerts->count(), 'recomendación grave nueva', 'recomendaciones graves nuevas'), "Clientes: {$names}".($alerts->count() > 3 ? ' y más.' : '.'), '/inteligencia/alertas', 'insight_high:'.$now->toDateString().':'.$user->id, 'warning');
        }
    }
}
