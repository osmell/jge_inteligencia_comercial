<?php

namespace App\Http\Controllers\Api\V1\Sales;

use App\Http\Controllers\Controller;
use App\Http\Requests\ProductsRequest;
use App\Services\ProductCatalog;
use Illuminate\Http\JsonResponse;

/** Consulta del catálogo de productos (solo lectura; se carga desde Importaciones). */
class ProductController extends Controller
{
    public function __construct(private readonly ProductCatalog $catalog) {}

    public function index(ProductsRequest $request): JsonResponse
    {
        $result = $this->catalog->search(
            $request->safe()->only(['q', 'company_id', 'level1', 'level2', 'level3']),
            $request->validated('sort') ?? 'name',
            $request->page(),
        );

        return response()->json(['data' => $result['rows'], 'meta' => ['page' => $request->page(), 'has_more' => $result['has_more']]]);
    }

    public function levels(ProductsRequest $request): JsonResponse
    {
        return response()->json(['data' => $this->catalog->levels($request->integer('company_id') ?: null, $request->validated('level1'), $request->validated('level2'))]);
    }

    public function missing(ProductsRequest $request): JsonResponse
    {
        $result = $this->catalog->missing($request->page());

        return response()->json(['data' => $result['rows'], 'meta' => ['page' => $request->page(), 'has_more' => $result['has_more']]]);
    }

    public function coverage(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->coverage()]);
    }
}
