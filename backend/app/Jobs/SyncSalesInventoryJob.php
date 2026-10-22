<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\SalesInventorySync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/** Descuenta del inventario las ventas pendientes (después de importar ventas o de cargar un inventario inicial). */
class SyncSalesInventoryJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public function __construct(public ?int $companyId = null, public ?int $branchId = null) {}

    public function handle(SalesInventorySync $sync): void
    {
        $sync->run($this->companyId, $this->branchId);
    }
}
