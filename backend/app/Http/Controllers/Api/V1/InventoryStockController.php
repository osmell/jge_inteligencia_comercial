<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\InventoryStockRequest;
use App\Models\Branch;
use App\Models\InventoryShortfall;
use App\Models\Product;
use App\Services\InventoryStock;
use Illuminate\Http\JsonResponse;

/** Existencias por sucursal y kárdex de un producto. Sin permiso inventory.costs la respuesta no trae importes. */
class InventoryStockController extends Controller
{
    public function __construct(private readonly InventoryStock $stock) {}

    public function index(InventoryStockRequest $request): JsonResponse
    {
        $result = $this->stock->stock($request->user(), $request->safe()->only(['company_id', 'branch_id', 'q', 'status']), $request->page());

        return response()->json([
            'data' => $result['rows'],
            'meta' => ['page' => $request->page(), 'has_more' => $result['has_more'], 'totals' => $result['totals'], 'costs_visible' => $this->stock->canSeeCosts($request->user()), 'open_shortfalls' => InventoryShortfall::query()->whereNull('resolved_at')->count()],
        ]);
    }

    public function kardex(InventoryStockRequest $request): JsonResponse
    {
        $product = Product::query()->where('company_id', $request->integer('company_id'))->with('baseUnit:id,name')->find($request->integer('product_id')) ?? abort(404, 'Producto no encontrado.');
        $branch = Branch::query()->where('company_id', $request->integer('company_id'))->find($request->integer('branch_id')) ?? abort(404, 'Sucursal no encontrada.');
        $result = $this->stock->kardex($request->user(), $branch->company_id, $branch->id, $product, $request->day('from'), $request->day('to'));

        return response()->json([
            'data' => $result,
            'meta' => ['product' => ['id' => $product->id, 'code' => $product->code, 'name' => $product->name, 'unit' => $product->baseUnit?->name], 'branch' => $branch->name, 'costs_visible' => $this->stock->canSeeCosts($request->user())],
        ]);
    }
}
