<?php

namespace App\Console\Commands;

use App\Services\SalesInventorySync;
use Illuminate\Console\Command;

class SyncSalesInventory extends Command
{
    protected $signature = 'inventory:sync-sales {--company= : Id de la compañía} {--branch= : Id de la sucursal}';

    protected $description = 'Descuenta del inventario las ventas pendientes de sucursales con inventario inicial (idempotente).';

    public function handle(SalesInventorySync $sync): int
    {
        $result = $sync->run($this->option('company') ? (int) $this->option('company') : null, $this->option('branch') ? (int) $this->option('branch') : null);
        $this->info("Sucursales: {$result['branches']}, líneas: {$result['lines']}, salidas: {$result['movements']}, faltantes: {$result['shortfalls']}.");

        return self::SUCCESS;
    }
}
