<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomersRequest;
use App\Services\CustomerInsights;
use Illuminate\Http\JsonResponse;

/** Ficha de cliente de solo lectura. Los datos se limitan al ámbito del usuario por los global scopes de ventas. */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerInsights $customers) {}

    public function index(CustomersRequest $request): JsonResponse
    {
        $result = $this->customers->search($request->validated('q'), $request->companyId(), $request->validated('sort') ?? 'last_purchase', $request->page());

        return response()->json(['data' => $result['rows'], 'meta' => ['page' => $request->page(), 'has_more' => $result['has_more']]]);
    }

    public function profile(CustomersRequest $request): JsonResponse
    {
        $profile = $this->customers->profile((int) $request->validated('company_id'), $request->validated('code'));
        abort_if($profile === null, 404, 'Cliente no encontrado.');

        return response()->json(['data' => $profile]);
    }

    public function purchases(CustomersRequest $request): JsonResponse
    {
        $result = $this->customers->purchases((int) $request->validated('company_id'), $request->validated('code'), $request->page());

        return response()->json(['data' => $result['rows'], 'meta' => ['page' => $request->page(), 'has_more' => $result['has_more']]]);
    }

    public function document(CustomersRequest $request): JsonResponse
    {
        $lines = $this->customers->documentLines((int) $request->validated('company_id'), $request->validated('code'), (int) $request->validated('document_id'));
        abort_if($lines === null, 404, 'Factura no encontrada.');

        return response()->json(['data' => $lines]);
    }
}
