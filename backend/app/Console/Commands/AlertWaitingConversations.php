<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ConversationAlerts;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class AlertWaitingConversations extends Command
{
    protected $signature = 'conversations:alert-waiting';

    protected $description = 'Avisa a supervisión de los clientes de WhatsApp que esperan respuesta (solo dentro de la ventana de avisos).';

    public function handle(ConversationAlerts $alerts): int
    {
        $now = CarbonImmutable::now();
        $window = (array) config('automations.window');
        if ($now->dayOfWeek === CarbonImmutable::SUNDAY || $now->hour < $window['start'] || $now->hour >= $window['end']) {
            return self::SUCCESS;
        }
        $this->info('Avisos creados: '.$alerts->waiting($now));

        return self::SUCCESS;
    }
}
