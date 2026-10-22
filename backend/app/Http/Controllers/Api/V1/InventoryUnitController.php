<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductUnitsRequest;
use App\Http\Requests\UnitRequest;
use App\Inventory\Quantity;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Services\AuditLogger;
use App\Services\ProductUnitsEditor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/** Catálogo de unidades y, por producto, su unidad base y sus equivalencias. */
class InventoryUnitController extends Controller
{
    public function __construct(private readonly AuditLogger $audit, private readonly ProductUnitsEditor $editor) {}

    public function units(): JsonResponse
    {
        return response()->json(['data' => Unit::query()->orderBy('name')->get(['id', 'code', 'name', 'active'])->all()]);
    }

    public function storeUnit(UnitRequest $request): JsonResponse
    {
        $unit = Unit::create($request->validated() + ['active' => true]);
        $this->audit->record('inventory.unit_created', $request->user(), auditable: $unit, new: $unit->only(['code', 'name', 'active']));

        return response()->json(['data' => $unit->only(['id', 'code', 'name', 'active'])], 201);
    }

    public function updateUnit(UnitRequest $request, Unit $unit): JsonResponse
    {
        $old = $unit->only(['name', 'active']);
        $unit->update($request->validated());
        if ($unit->wasChanged()) {
            $this->audit->record('inventory.unit_updated', $request->user(), auditable: $unit, old: $old, new: $unit->only(['name', 'active']));
        }

        return response()->json(['data' => $unit->only(['id', 'code', 'name', 'active'])]);
    }

    /** Búsqueda de productos con su configuración de unidades. */
    public function products(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'company_id' => ['nullable', 'integer', 'min:1'], 'missing_base' => ['nullable', 'boolean'], 'page' => ['nullable', 'integer', 'min:1', 'max:100000'], 'per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $perPage = (int) ($data['per_page'] ?? 25);
        $page = (int) ($data['page'] ?? 1);
        $like = isset($data['q']) ? '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower($data['q'])).'%' : null;
        $query = Product::query()
            ->when(isset($data['company_id']), fn ($q) => $q->where('company_id', $data['company_id']))
            ->when($request->boolean('missing_base'), fn ($q) => $q->whereNull('base_unit_id'))
            ->when($like !== null, fn ($q) => $q->where(fn ($w) => $w->whereRaw("lower(code) like ? escape '\\'", [$like])->orWhereRaw("lower(name) like ? escape '\\'", [$like])));
        $total = (clone $query)->count();
        $rows = $query->with('baseUnit:id,code,name')->withCount('equivalences')->orderBy('name')->orderBy('id')->offset(($page - 1) * $perPage)->limit($perPage)->get();

        return response()->json(['meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'last_page' => max(1, (int) ceil($total / $perPage))], 'data' => $rows->map(fn (Product $p) => [
            'id' => $p->id, 'company_id' => $p->company_id, 'code' => $p->code, 'name' => $p->name,
            'base_unit' => $p->baseUnit?->only(['id', 'code', 'name']), 'equivalences_count' => $p->getAttribute('equivalences_count'),
        ])->all()]);
    }

    public function productUnits(int $product): JsonResponse
    {
        return response()->json(['data' => $this->present(Product::query()->findOrFail($product))]);
    }

    public function updateProductUnits(ProductUnitsRequest $request, int $product): JsonResponse
    {
        $record = Product::query()->findOrFail($product);
        $baseId = $request->integer('base_unit_id');
        $equivalences = (array) $request->validated('equivalences');

        if ($record->base_unit_id !== null && $record->base_unit_id !== $baseId && InventoryMovement::query()->where('product_id', $record->id)->exists()) {
            throw ValidationException::withMessages(['base_unit_id' => 'La unidad base no se puede cambiar: el producto ya tiene movimientos de inventario.']);
        }

        $rows = [];
        foreach ($equivalences as $i => $e) {
            if ((int) $e['unit_id'] === $baseId) {
                throw ValidationException::withMessages(["equivalences.$i.unit_id" => 'La unidad base no necesita equivalencia.']);
            }
            try {
                [$num, $den] = Quantity::factor((string) $e['factor']);
            } catch (InvalidArgumentException $ex) {
                throw ValidationException::withMessages(["equivalences.$i.factor" => $ex->getMessage()]);
            }
            $rows[(int) $e['unit_id']] = ['factor_num' => $num, 'factor_den' => $den];
        }

        $old = $this->present($record);
        $this->editor->save($record, $baseId, $rows);
        $new = $this->present($record->refresh());
        if ($old !== $new) {
            $this->audit->record('inventory.product_units_updated', $request->user(), auditable: $record, old: $old, new: $new, companyId: $record->company_id);
        }

        return response()->json(['data' => $new]);
    }

    /** @return array<string, mixed> */
    private function present(Product $product): array
    {
        $product->load('baseUnit:id,code,name', 'equivalences.unit:id,code,name');

        return [
            'id' => $product->id, 'company_id' => $product->company_id, 'code' => $product->code, 'name' => $product->name,
            'base_unit' => $product->baseUnit?->only(['id', 'code', 'name']),
            'equivalences' => $product->equivalences->sortBy('unit.name')->values()->map(fn (ProductUnit $e) => [
                'unit_id' => $e->unit_id, 'unit' => $e->unit?->name, 'factor' => $e->factorText(),
            ])->all(),
        ];
    }
}
