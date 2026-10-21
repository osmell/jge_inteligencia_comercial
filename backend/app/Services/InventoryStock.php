<?php

namespace App\Services;

use App\Imports\Decimal;
use App\Inventory\Quantity;
use App\Models\InventoryBalance;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Consulta de existencias y kárdex. Los costos y la valuación solo los ve quien tenga el permiso inventory.costs; para el
 * resto la respuesta no incluye ningún importe. Una existencia es «conciliada» cuando su sucursal tiene inventario inicial:
 * sin él, el saldo se calcula solo con movimientos parciales y se marca para no confundirlo con una existencia real.
 */
class InventoryStock
{
    public const PAGE_SIZE = 25;

    public const KARDEX_LIMIT = 500;

    public function canSeeCosts(User $user): bool
    {
        return $user->hasPermission('inventory.costs');
    }

    /**
     * @param  array{company_id?: int|null, branch_id?: int|null, q?: string|null, status?: string|null}  $filters
     * @return array{rows: list<array<string, mixed>>, has_more: bool, totals: array<string, mixed>}
     */
    public function stock(User $user, array $filters, int $page): array
    {
        $costs = $this->canSeeCosts($user);
        $status = $filters['status'] ?? 'with';
        $query = InventoryBalance::query()->with(['product.baseUnit:id,code,name', 'branch:id,name', 'company:id,name'])
            ->when(! empty($filters['company_id']), fn (Builder $q) => $q->where('inventory_balances.company_id', $filters['company_id']))
            ->when(! empty($filters['branch_id']), fn (Builder $q) => $q->where('inventory_balances.branch_id', $filters['branch_id']))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters) {
                $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower((string) $filters['q'])).'%';
                $q->whereHas('product', fn (Builder $p) => $p->where(fn (Builder $w) => $w->whereRaw("lower(products.code) like ? escape '\\'", [$like])->orWhereRaw("lower(products.name) like ? escape '\\'", [$like])));
            });
        if ($status === 'with') {
            $query->where('inventory_balances.quantity', '>', 0);
        } elseif ($status === 'zero') {
            $query->where('inventory_balances.quantity', '<=', 0);
        }

        $totals = ['products' => (clone $query)->toBase()->count()];
        if ($costs) {
            $totals['value'] = number_format((float) (clone $query)->toBase()->sum('inventory_balances.value'), 2, '.', '');
        }

        $balances = $query->join('products as p', 'p.id', '=', 'inventory_balances.product_id')->select('inventory_balances.*')
            ->orderBy('p.name')->orderBy('inventory_balances.id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        $reconciled = $this->reconciledBranches($balances->pluck('branch_id')->unique()->all());
        $rows = $balances->take(self::PAGE_SIZE)->map(function (InventoryBalance $b) use ($costs, $reconciled) {
            $qty = Quantity::units((string) $b->quantity);
            $row = [
                'company_id' => $b->company_id, 'company' => $b->company->name, 'branch_id' => $b->branch_id, 'branch' => $b->branch->name,
                'product' => ['id' => $b->product_id, 'code' => $b->product->code, 'name' => $b->product->name],
                'unit' => $b->product->baseUnit?->name, 'quantity' => Quantity::display($qty), 'reconciled' => isset($reconciled[$b->branch_id]),
            ];
            if ($costs) {
                $value = Decimal::units((string) $b->value);
                $row['value'] = Decimal::format($value);
                $row['average_cost'] = $qty > 0 ? number_format($value / 10 ** Decimal::SCALE / ($qty / 10 ** Quantity::SCALE), 4, '.', '') : null;
            }

            return $row;
        })->values()->all();

        return ['rows' => $rows, 'has_more' => $balances->count() > self::PAGE_SIZE, 'totals' => $totals];
    }

    /**
     * Movimientos de un producto en una sucursal con el saldo corrido. El saldo inicial es lo acumulado antes de «desde».
     *
     * @return array{opening: array<string, mixed>, rows: list<array<string, mixed>>, truncated: bool, closing: array<string, mixed>, reconciled: bool}
     */
    public function kardex(User $user, int $companyId, int $branchId, Product $product, ?CarbonImmutable $from, ?CarbonImmutable $to): array
    {
        $costs = $this->canSeeCosts($user);
        $base = InventoryMovement::query()->where(['company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $product->id]);

        $qty = 0;
        $value = 0;
        if ($from !== null) {
            foreach ((clone $base)->where('occurred_on', '<', $from->toDateString())->get(['quantity', 'value']) as $m) {
                $qty += Quantity::units((string) $m->quantity);
                $value += Decimal::units((string) $m->value);
            }
        }
        $opening = ['quantity' => Quantity::display($qty)] + ($costs ? ['value' => Decimal::format($value)] : []);

        $movements = (clone $base)->with(['user:id,name'])
            ->when($from !== null, fn (Builder $q) => $q->where('occurred_on', '>=', $from->toDateString()))
            ->when($to !== null, fn (Builder $q) => $q->where('occurred_on', '<=', $to->toDateString()))
            ->orderBy('occurred_on')->orderBy('id')->limit(self::KARDEX_LIMIT + 1)->get();
        $reversed = InventoryMovement::query()->whereIn('reverses_id', $movements->pluck('id'))->pluck('reverses_id')->all();
        $units = DB::table('units')->pluck('name', 'id');

        $rows = [];
        foreach ($movements->take(self::KARDEX_LIMIT) as $m) {
            $qty += Quantity::units((string) $m->quantity);
            $value += Decimal::units((string) $m->value);
            $row = [
                'id' => $m->id, 'date' => $m->occurred_on->toDateString(), 'type' => $m->type, 'quantity' => Quantity::display(Quantity::units((string) $m->quantity)),
                'entered' => Quantity::display(Quantity::units((string) $m->entered_quantity)), 'entered_unit' => $m->unit_id ? ($units[$m->unit_id] ?? null) : null,
                'reference' => $m->reference, 'note' => $m->note, 'user' => $m->user?->name, 'reverses_id' => $m->reverses_id, 'reversed' => in_array($m->id, $reversed, true),
                'balance' => Quantity::display($qty),
            ];
            if ($costs) {
                $row['value'] = Decimal::format(Decimal::units((string) $m->value));
                $row['balance_value'] = Decimal::format($value);
            }
            $rows[] = $row;
        }

        $closing = ['quantity' => Quantity::display($qty)] + ($costs ? ['value' => Decimal::format($value)] : []);

        return ['opening' => $opening, 'rows' => $rows, 'truncated' => $movements->count() > self::KARDEX_LIMIT, 'closing' => $closing, 'reconciled' => isset($this->reconciledBranches([$branchId])[$branchId])];
    }

    /**
     * Sucursales con inventario inicial.
     *
     * @param  list<int>  $branchIds
     * @return array<int, true>
     */
    private function reconciledBranches(array $branchIds): array
    {
        if ($branchIds === []) {
            return [];
        }

        return array_fill_keys(InventoryMovement::query()->where('type', InventoryMovement::OPENING)->whereIn('branch_id', $branchIds)->distinct()->pluck('branch_id')->map(fn ($id) => (int) $id)->all(), true);
    }
}
