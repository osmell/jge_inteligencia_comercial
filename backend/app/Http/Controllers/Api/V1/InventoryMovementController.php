<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Inventory\Quantity;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Unit;
use App\Services\InventoryOperations;
use App\Services\InventoryStock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Movimientos manuales de inventario: ajustes, devoluciones, traslados y reversas, y su consulta. */
class InventoryMovementController extends Controller
{
    private const PAGE_SIZE = 25;

    /** Tipos que muestra la consulta: lo capturado a mano y las entradas importadas (que también se pueden revertir). */
    private const LISTED = [...InventoryMovement::REVERSIBLE, InventoryMovement::REVERSAL];

    public function __construct(private readonly InventoryOperations $operations, private readonly InventoryStock $stock) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'type' => ['nullable', Rule::in(self::LISTED)],
            'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $costs = $this->stock->canSeeCosts($request->user());
        $like = isset($data['q']) ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($data['q'])).'%' : null;

        $rows = InventoryMovement::query()->with(['product:id,code,name', 'user:id,name'])->whereIn('type', self::LISTED)
            ->when(! empty($data['company_id']), fn (Builder $q) => $q->where('company_id', $data['company_id']))
            ->when(! empty($data['branch_id']), fn (Builder $q) => $q->where('branch_id', $data['branch_id']))
            ->when(! empty($data['type']), fn (Builder $q) => $q->where('type', $data['type']))
            ->when($like !== null, fn (Builder $q) => $q->whereHas('product', fn (Builder $p) => $p->where(fn (Builder $w) => $w->whereRaw("lower(products.code) like ? escape '\\'", [$like])->orWhereRaw("lower(products.name) like ? escape '\\'", [$like]))))
            ->orderByDesc('occurred_on')->orderByDesc('id')->offset(($page - 1) * self::PAGE_SIZE)->limit(self::PAGE_SIZE + 1)->get();

        $slice = $rows->take(self::PAGE_SIZE);
        $reversed = InventoryMovement::query()->whereIn('reverses_id', $slice->pluck('id'))->pluck('reverses_id')->all();
        $branches = Branch::withoutGlobalScopes()->whereIn('id', $slice->pluck('branch_id'))->pluck('name', 'id');
        $units = Unit::query()->pluck('name', 'id');

        return response()->json([
            'data' => $slice->map(function (InventoryMovement $m) use ($costs, $reversed, $branches, $units) {
                $isReversed = in_array($m->id, $reversed, true);
                $row = [
                    'id' => $m->id, 'date' => $m->occurred_on->toDateString(), 'type' => $m->type, 'reason' => $m->reason, 'branch' => $branches[$m->branch_id] ?? '',
                    'product' => ['id' => $m->product_id, 'code' => $m->product->code, 'name' => $m->product->name],
                    'quantity' => Quantity::display(Quantity::units((string) $m->quantity)), 'entered' => Quantity::display(Quantity::units((string) $m->entered_quantity)),
                    'entered_unit' => $m->unit_id ? ($units[$m->unit_id] ?? null) : null, 'reference' => $m->reference, 'note' => $m->note, 'user' => $m->user?->name,
                    'reversed' => $isReversed, 'reversible' => ! $isReversed && in_array($m->type, InventoryMovement::REVERSIBLE, true),
                ];
                if ($costs) {
                    $row['value'] = $m->value;
                }

                return $row;
            })->values()->all(),
            'meta' => ['page' => $page, 'has_more' => $rows->count() > self::PAGE_SIZE, 'costs_visible' => $costs],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in([InventoryMovement::ADJUSTMENT_IN, InventoryMovement::ADJUSTMENT_OUT, InventoryMovement::RETURN_IN, InventoryMovement::SUPPLIER_RETURN])],
            'company_id' => ['required', 'integer', 'min:1'], 'branch_id' => ['required', 'integer', 'min:1'], 'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'regex:/^\d{1,8}(\.\d{1,8})?$/'], 'unit_id' => ['nullable', 'integer', 'exists:units,id'],
            'occurred_on' => ['required', 'date_format:Y-m-d'], 'reason' => ['required', 'string', 'max:30'], 'note' => ['nullable', 'string', 'max:500'], 'unit_cost' => ['nullable', 'string', 'max:20'],
        ]);
        $product = Product::query()->where('company_id', $data['company_id'])->findOrFail((int) $data['product_id']);

        $movement = $this->operations->record(
            $request->user(), $data['company_id'], $data['branch_id'], $product, $data['type'], $data['quantity'], $data['unit_id'] ?? null,
            CarbonImmutable::parse($data['occurred_on']), $data['reason'], $data['note'] ?? null, $data['unit_cost'] ?? null,
        );

        return response()->json(['data' => ['id' => $movement->id]], 201);
    }

    public function transfer(Request $request): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['required', 'integer', 'min:1'], 'from_branch_id' => ['required', 'integer', 'min:1'], 'to_branch_id' => ['required', 'integer', 'min:1'], 'product_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'regex:/^\d{1,8}(\.\d{1,8})?$/'], 'unit_id' => ['nullable', 'integer', 'exists:units,id'], 'occurred_on' => ['required', 'date_format:Y-m-d'], 'note' => ['nullable', 'string', 'max:500'],
        ]);
        $product = Product::query()->where('company_id', $data['company_id'])->findOrFail((int) $data['product_id']);

        $result = $this->operations->transfer(
            $request->user(), $data['company_id'], $data['from_branch_id'], $data['to_branch_id'], $product, $data['quantity'], $data['unit_id'] ?? null, CarbonImmutable::parse($data['occurred_on']), $data['note'] ?? null,
        );

        return response()->json(['data' => ['reference' => $result['out']->reference]], 201);
    }

    public function reverse(Request $request, int $movement): JsonResponse
    {
        $data = $request->validate(['note' => ['required', 'string', 'max:500']]);
        $this->operations->reverse(InventoryMovement::query()->findOrFail($movement), $request->user(), $data['note']);

        return response()->json(['data' => ['reversed' => true]]);
    }
}
