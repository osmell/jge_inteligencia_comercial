<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\AutomationRunner;
use Illuminate\Console\Command;

class RunAutomations extends Command
{
    protected $signature = 'automations:run';

    protected $description = 'Ejecuta las automatizaciones que les toca (activas, a su hora y dentro de la ventana), reintenta las fallidas y registra cada ejecución.';

    public function handle(AutomationRunner $runner): int
    {
        $r = $runner->tick();
        $this->info("Iniciadas: {$r['started']}, reintentadas: {$r['retried']}, interrumpidas: {$r['interrupted']}.");

        return self::SUCCESS;
    }
}
