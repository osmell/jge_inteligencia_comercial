<?php

namespace App\Console\Commands;

use App\Services\InsightsRefresher;
use Illuminate\Console\Command;

class RefreshInsights extends Command
{
    protected $signature = 'insights:refresh {--company= : Id de la compañía}';

    protected $description = 'Recalcula la inteligencia comercial (RFM, concentración, cohortes, venta cruzada, riesgo de inactividad, cambios anormales y estacionalidad) de las compañías activas; cada cálculo queda registrado.';

    public function handle(InsightsRefresher $refresher): int
    {
        foreach ($refresher->refresh($this->option('company') ? (int) $this->option('company') : null) as $r) {
            $this->line("{$r['company']} · {$r['analysis']}: ".($r['status'] === 'ok' ? 'calculado' : (string) $r['note']));
        }

        return self::SUCCESS;
    }
}
