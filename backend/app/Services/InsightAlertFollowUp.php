<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\InsightAlert;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Convierte una alerta abierta en una próxima acción planeada en la agenda del cliente. Nunca contacta por sí sola. */
class InsightAlertFollowUp
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function create(User $user, InsightAlert $alert, Customer $customer, string $nextAction, string $nextActionAt): CustomerInteraction
    {
        $interaction = DB::transaction(function () use ($user, $alert, $customer, $nextAction, $nextActionAt) {
            $interaction = CustomerInteraction::create([
                'company_id' => $customer->company_id, 'customer_id' => $customer->id, 'user_id' => $user->id, 'channel' => 'other', 'direction' => 'outbound', 'outcome' => CustomerInteraction::PLANNED,
                'occurred_at' => now(), 'response' => 'Acción planeada desde una recomendación: '.mb_substr($alert->explanation, 0, 400), 'next_action' => $nextAction, 'next_action_at' => $nextActionAt, 'insight_alert_id' => $alert->id,
            ]);
            $alert->forceFill(['follow_up_interaction_id' => $interaction->id])->save();

            return $interaction;
        });
        $this->audit->record('insights.alert_follow_up_created', $user, auditable: $alert, new: ['interaction_id' => $interaction->id, 'next_action' => $nextAction, 'next_action_at' => $nextActionAt], companyId: $alert->company_id);

        return $interaction;
    }
}
