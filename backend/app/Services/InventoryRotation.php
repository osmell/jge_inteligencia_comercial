<?php

namespace App\Services;

use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Rotación, cobertura, mínimos, máximos y alertas de reposición por producto y sucursal.
 *
 * - Demanda diaria = unidades vendidas en los últimos N días ÷ N (solo ventas; no cuentan traslados ni ajustes).
 * - Cobertura (días) = existencia ÷ demanda diaria. Rotación = unidades vendidas en la ventana ÷ existencia promedio de la ventana
 *   (promedio entre la existencia al inicio y la actual).
 * - Mínimo = demanda diaria × (reposición + seguridad); máximo = mínimo + ciclo de compra. Los días son configurables (config/inventory.php).
 * - Estado, por prioridad: agotado (sin existencia pero con demanda), bajo el mínimo, sin rotación (existencia sin ventas en M días),
 *   sobre el máximo y normal.
 * Solo con el permiso inventory.costs se incluye el valor inmovilizado.
 */
class InventoryRotation
{
    public const PAGE_SIZE = 25;

    /** Prioridad de los estados para ordenar la lista. */
    public const STATUSES = ['out_of_stock', 'below_min', 'no_rotation', 'above_max', 'ok'];

    public function __construct(private readonly InventoryStock $stock) {}

    /** @return array<string, int> */
    public function params(): array
    {
        $c = (array) config('inventory.rotation');

        return [
            'demand_days' => max(1, (int) $c['demand_days']), 'no_movement_days' => max(1, (int) $c['no_movement_days']),
            'reorder_days' => (int) $c['reorder_days'], 'safety_days' => (int) $c['safety_days'], 'cycle_days' => (int) $c['cycle_days'],
        ];
    }

    /**
     * @param  array{company_id?: int|null, branch_id?: int|null, q?: string|null, status?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>, params: array<string, int>}
     */
    public function report(?User $user, array $filters, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $p = $this->params();
        $costs = $this->stock->canSeeCosts($user);
        $from = $today->subDays($p['demand_days'] - 1)->toDateString();
        $noMovementFrom = $today->subDays($p['no_movement_days'] - 1)->toDateString();

        $moves = DB::table('inventory_movements')->selectRaw(
            'company_id, branch_id, product_id,'
            .' sum(case when type = ? and occurred_on >= ? then -quantity else 0 end) as sold,'
            .' sum(case when occurred_on >= ? then quantity else 0 end) as net,'
            .' max(case when type = ? then occurred_on end) as last_sale',
            [InventoryMovement::SALE, $from, $from, InventoryMovement::SALE],
        )->groupBy('company_id', 'branch_id', 'product_id');

        $query = InventoryBalance::query()->join('products as p', 'p.id', '=', 'inventory_balances.product_id')->join('branches as b', 'b.id', '=', 'inventory_balances.branch_id')
            ->leftJoinSub($moves, 'm', fn ($j) => $j->on('m.company_id', '=', 'inventory_balances.company_id')->on('m.branch_id', '=', 'inventory_balances.branch_id')->on('m.product_id', '=', 'inventory_balances.product_id'))
            ->when(! empty($filters['company_id']), fn (Builder $q) => $q->where('inventory_balances.company_id', $filters['company_id']))
            ->when(! empty($filters['branch_id']), fn (Builder $q) => $q->where('inventory_balances.branch_id', $filters['branch_id']))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower((string) $filters['q'])).'%';
                $q->where(fn (Builder $w) => $w->whereRaw("lower(p.code) like ? escape '\\'", [$like])->orWhereRaw("lower(p.name) like ? escape '\\'", [$like]));
            })
            ->select('inventory_balances.product_id', 'inventory_balances.branch_id', 'inventory_balances.company_id', 'inventory_balances.quantity', 'inventory_balances.value', 'p.code as product_code', 'p.name as product_name', 'b.name as branch_name')
            ->selectRaw('coalesce(m.sold, 0) as sold, coalesce(m.net, 0) as net, m.last_sale as last_sale');

        $counts = array_fill_keys(self::STATUSES, 0);
        $rows = [];
        foreach ($query->toBase()->get() as $b) {
            $stock = (float) $b->quantity;
            $sold = (float) $b->sold;
            $lastSale = $b->last_sale;
            if ($stock <= 0 && $sold <= 0) {
                continue;   // sin existencia y sin demanda: no hay nada que reponer ni vigilar
            }

            $daily = $sold / $p['demand_days'];
            $min = $daily * ($p['reorder_days'] + $p['safety_days']);
            $max = $min + $daily * $p['cycle_days'];
            $startStock = $stock - (float) $b->net;
            $average = ($startStock + $stock) / 2;
            $status = match (true) {
                $stock <= 0 => 'out_of_stock',
                $daily > 0 && $stock < $min => 'below_min',
                $lastSale === null || $lastSale < $noMovementFrom => 'no_rotation',
                $daily > 0 && $stock > $max => 'above_max',
                default => 'ok',
            };
            $counts[$status]++;

            $row = [
                'company_id' => $b->company_id, 'branch_id' => $b->branch_id, 'branch' => $b->branch_name, 'product' => ['id' => $b->product_id, 'code' => $b->product_code, 'name' => $b->product_name],
                'stock' => $this->n($stock), 'sold' => $this->n($sold), 'daily_demand' => $this->n($daily, 4), 'coverage_days' => $daily > 0 ? $this->n($stock / $daily, 1) : null,
                'rotation' => $average > 0 ? $this->n($sold / $average, 2) : null, 'min' => $this->n($min, 2), 'max' => $this->n($max, 2),
                'suggested' => $status === 'below_min' || $status === 'out_of_stock' ? $this->n(max(0, $max - $stock), 2) : null,
                'last_sale' => $lastSale === null ? null : CarbonImmutable::parse($lastSale)->toDateString(),
                'days_since_sale' => $lastSale === null ? null : (int) CarbonImmutable::parse($lastSale)->startOfDay()->diffInDays($today),
                'status' => $status,
            ];
            if ($costs) {
                $row['value'] = number_format((float) $b->value, 2, '.', '');
            }
            $rows[] = $row;
        }

        $status = $filters['status'] ?? 'all';
        if ($status !== 'all') {
            $rows = array_values(array_filter($rows, fn (array $r) => $r['status'] === $status));
        }
        usort($rows, fn (array $a, array $b) => [array_search($a['status'], self::STATUSES, true), $a['coverage_days'] ?? PHP_INT_MAX, $a['product']['name']] <=> [array_search($b['status'], self::STATUSES, true), $b['coverage_days'] ?? PHP_INT_MAX, $b['product']['name']]);

        return ['rows' => $rows, 'counts' => $counts, 'params' => $p];
    }

    private function n(float $value, int $decimals = 4): string
    {
        $text = number_format($value, $decimals, '.', '');

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }
}
