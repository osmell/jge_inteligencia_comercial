<?php

namespace App\Automations;

use App\Services\InventoryRotation;
use App\Services\Notifier;
use Carbon\CarbonImmutable;

/** A cada persona con acceso al inventario, un resumen diario de los productos agotados y bajo el mínimo de las sucursales que ve. */
class InventoryLowHandler extends NoticeHandler
{
    public function __construct(Notifier $notifier, private readonly InventoryRotation $rotation)
    {
        parent::__construct($notifier);
    }

    protected function notices(array $params): void
    {
        $today = CarbonImmutable::today();
        foreach ($this->notifier->usersWith('inventory.view') as $user) {
            $report = $this->notifier->scopedAs($user, fn () => $this->rotation->report($user, []));
            $out = $report['counts']['out_of_stock'];
            $low = $report['counts']['below_min'];
            if ($out + $low === 0) {
                continue;
            }
            $worst = collect($report['rows'])->whereIn('status', ['out_of_stock', 'below_min'])->take(3)->map(fn (array $r) => "{$r['product']['name']} ({$r['branch']})")->implode(', ');
            $parts = array_filter([$out > 0 ? $this->plural($out, 'producto agotado', 'productos agotados') : null, $low > 0 ? $this->plural($low, 'producto bajo el mínimo', 'productos bajo el mínimo') : null]);
            $this->notify($user, 'inventory_low', implode(' y ', $parts), 'Los más urgentes: '.$worst.'.', '/inventario/rotacion', 'inventory_low:'.$today->toDateString().':'.$user->id, $out > 0 ? 'danger' : 'warning');
        }
    }
}
