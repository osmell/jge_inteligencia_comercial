<?php

namespace App\Services;

use App\Models\WhatsappConversation;
use App\Models\WhatsappSetting;
use Carbon\CarbonImmutable;

/** Mensaje de ausencia: cuando el cliente escribe fuera del horario de atención se le contesta una vez (como máximo cada 12 horas). */
class ConversationAway
{
    private const EVERY_HOURS = 12;

    public function __construct(private readonly ConversationSender $sender) {}

    public function handle(WhatsappConversation $c, ?CarbonImmutable $now = null): bool
    {
        $s = WhatsappSetting::current();
        $now ??= CarbonImmutable::now();
        if (! $s->away_enabled || trim((string) $s->away_message) === '' || ! $this->closedAt($s, $now)) {
            return false;
        }
        $c->refresh();
        if ($c->last_away_at !== null && $c->last_away_at->gt($now->subHours(self::EVERY_HOURS))) {
            return false;
        }
        if ($this->sender->automatic($c, (string) $s->away_message) === null) {
            return false;
        }
        $c->forceFill(['last_away_at' => $now])->save();

        return true;
    }

    /** Fuera del horario: un día que no se atiende, o antes de la hora de inicio o desde la de cierre. */
    public function closedAt(WhatsappSetting $s, CarbonImmutable $now): bool
    {
        $days = array_map('intval', array_filter(explode(',', (string) $s->away_days), fn (string $d) => $d !== ''));

        return ! in_array($now->isoWeekday(), $days, true) || $now->hour < (int) $s->away_start_hour || $now->hour >= (int) $s->away_end_hour;
    }
}
