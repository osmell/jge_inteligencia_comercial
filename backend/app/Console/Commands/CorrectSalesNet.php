<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sales\NetCorrection;
use Illuminate\Console\Command;

class CorrectSalesNet extends Command
{
    protected $signature = 'sales:correct-net {--company= : Id de la compañía}';

    protected $description = 'Calcula el neto corregido de las líneas vendidas por equivalencia que aún no lo tienen (idempotente; no modifica las ventas).';

    public function handle(NetCorrection $correction): int
    {
        $this->info('Líneas corregidas: '.$correction->run($this->option('company') ? (int) $this->option('company') : null).'.');

        return self::SUCCESS;
    }
}
