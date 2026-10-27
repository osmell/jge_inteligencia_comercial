<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Sales\ComponentCosts;
use App\Services\Sales\NetCorrection;
use Illuminate\Console\Command;

class CorrectSalesNet extends Command
{
    protected $signature = 'sales:correct-net {--company= : Id de la compañía}';

    protected $description = 'Calcula el neto corregido de las líneas vendidas por equivalencia y reasigna el costo de los componentes a su línea principal, donde aún no está hecho (idempotente; no modifica las ventas).';

    public function handle(NetCorrection $correction, ComponentCosts $components): int
    {
        $company = $this->option('company') ? (int) $this->option('company') : null;
        $this->info('Líneas corregidas: '.$correction->run($company).'. Componentes asignados: '.$components->run($company).'.');

        return self::SUCCESS;
    }
}
