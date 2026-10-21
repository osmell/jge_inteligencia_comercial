<?php

namespace App\Console\Commands;

use App\Services\Notifier;
use Illuminate\Console\Command;

class PruneNotifications extends Command
{
    protected $signature = 'notifications:prune';

    protected $description = 'Borra los avisos internos vistos o descartados más antiguos que el tiempo de conservación.';

    public function handle(Notifier $notifier): int
    {
        $this->info('Avisos borrados: '.$notifier->prune());

        return self::SUCCESS;
    }
}
