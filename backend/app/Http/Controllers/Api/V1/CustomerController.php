<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ActivityRulesRequest;
use App\Http\Requests\CustomersRequest;
use App\Services\AuditLogger;
use App\Services\CustomerActivity;
use App\Services\CustomerInsights;
use App\Services\Customers\CustomerPurchases;
use App\Services\Customers\CustomerSearch;
use Illuminate\Http\JsonResponse;

/** Ficha de cliente de solo lectura. Los datos se limitan al ámbito del usuario por los global scopes de ventas. */
class CustomerController extends Controller
{
    public function __construct(
        private readonly CustomerInsights $customers,
        private readonly CustomerSearch $search,
        private readonly CustomerPurchases $purchases,
        private readonly CustomerActivity $activity,
    ) {}

    public function index(CustomersRequest $request): JsonResponse
    {
        $result = $this->search->search($request->validated('q'), $request->companyId(), $request->validated('sort') ?? 'last_purchase', $request->page(), $request->integer('type_id') ?: null, $request->validated('status'));

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
        $result = $this->purchases->purchases((int) $request->validated('company_id'), $request->validated('code'), $request->page());

        return response()->json(['data' => $result['rows'], 'meta' => ['page' => $request->page(), 'has_more' => $result['has_more']]]);
    }

    public function document(CustomersRequest $request): JsonResponse
    {
        $lines = $this->purchases->documentLines((int) $request->validated('company_id'), $request->validated('code'), (int) $request->validated('document_id'));
        abort_if($lines === null, 404, 'Factura no encontrada.');

        return response()->json(['data' => $lines]);
    }

    public function activityRules(): JsonResponse
    {
        return response()->json(['data' => $this->activity->rules()]);
    }

    public function updateActivityRules(ActivityRulesRequest $request, AuditLogger $audit): JsonResponse
    {
        return response()->json(['data' => $this->activity->saveRules($request->user(), (int) $request->validated('inactive_days'), (int) $request->validated('lost_days'), $audit)]);
    }
}
