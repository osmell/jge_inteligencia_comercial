<?php

declare(strict_types=1);

namespace App\Services\Reports\Branches;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Existencias, rotación y faltantes de cada sucursal A LA FECHA, solo en cantidades de productos: estos reportes no muestran costos
 * ni valuación (eso exige el permiso `inventory.costs`).
 *
 *  - sin rotación: productos con existencia y sin ventas en esa sucursal en los últimos `inventory.rotation.no_movement_days` días;
 *  - agotados con demanda: productos vendidos en la ventana de demanda (`demand_days`) cuya existencia hoy es cero o menor;
 *  - faltantes abiertos: ventas que superaron la existencia y siguen sin resolver.
 */
class BranchInventoryFigures
{
    /**
     * @param  list<int>|null  $companyIds
     * @param  list<int>  $branchIds
     * @return array<int, array<string, mixed>> sucursal → cifras
     */
    public function snapshot(?array $companyIds, array $branchIds, CarbonImmutable $today): array
    {
        $noMovementFrom = $today->subDays((int) config('inventory.rotation.no_movement_days'))->toDateString();
        $demandFrom = $today->subDays((int) config('inventory.rotation.demand_days') - 1)->toDateString();
        $scope = fn ($q, string $company, string $branch) => $q->whereIn($branch, $branchIds)->when($companyIds !== null, fn ($q) => $q->whereIn($company, (array) $companyIds));

        $inStock = $scope(DB::table('inventory_balances as b')->where('b.quantity', '>', 0), 'b.company_id', 'b.branch_id')
            ->selectRaw('b.branch_id as branch_id, count(*) as total')->groupBy('b.branch_id')->pluck('total', 'branch_id');

        $noRotation = $scope(DB::table('inventory_balances as b')->join('products as p', 'p.id', '=', 'b.product_id')->where('b.quantity', '>', 0), 'b.company_id', 'b.branch_id')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales_lines as sl')
                ->whereColumn('sl.branch_id', 'b.branch_id')->whereColumn('sl.company_id', 'b.company_id')->whereColumn('sl.product_code', 'p.code')
                ->where('sl.issued_on', '>=', $noMovementFrom))
            ->selectRaw('b.branch_id as branch_id, count(*) as total')->groupBy('b.branch_id')->pluck('total', 'branch_id');

        $sold = $scope(DB::table('sales_lines as sl')->join('products as p', fn ($j) => $j->on('p.company_id', '=', 'sl.company_id')->on('p.code', '=', 'sl.product_code'))
            ->where('sl.units', '>', 0)->where('sl.issued_on', '>=', $demandFrom)->where('sl.issued_on', '<', $today->addDay()->toDateString()), 'sl.company_id', 'sl.branch_id')
            ->leftJoin('inventory_balances as b', fn ($j) => $j->on('b.product_id', '=', 'p.id')->on('b.branch_id', '=', 'sl.branch_id'))
            ->selectRaw('sl.branch_id as branch_id, count(distinct p.id) as sold, count(distinct case when coalesce(b.quantity, 0) <= 0 then p.id end) as out_of_stock')
            ->groupBy('sl.branch_id')->get()->keyBy('branch_id');

        $shortfalls = $scope(DB::table('inventory_shortfalls as s')->whereNull('s.resolved_at'), 's.company_id', 's.branch_id')
            ->selectRaw('s.branch_id as branch_id, count(*) as total')->groupBy('s.branch_id')->pluck('total', 'branch_id');

        $out = [];
        foreach ($branchIds as $id) {
            $stock = (int) ($inStock[$id] ?? 0);
            $stale = (int) ($noRotation[$id] ?? 0);
            $soldProducts = (int) ($sold[$id]->sold ?? 0);
            $out_ = (int) ($sold[$id]->out_of_stock ?? 0);
            $out[$id] = [
                'products_in_stock' => $stock,
                'no_rotation' => $stale,
                'no_rotation_pct' => $stock > 0 ? round($stale / $stock * 100, 2) : null,
                'sold_products' => $soldProducts,
                'stockout_with_demand' => $out_,
                'stockout_demand_pct' => $soldProducts > 0 ? round($out_ / $soldProducts * 100, 2) : null,
                'open_shortfalls' => (int) ($shortfalls[$id] ?? 0),
            ];
        }

        return $out;
    }
}
