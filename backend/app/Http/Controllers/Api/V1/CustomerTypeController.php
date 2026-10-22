<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerAssignTypeRequest;
use App\Http\Requests\CustomerTypeRequest;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;

/** Catálogo permanente de tipos de cliente (se desactivan, no se borran) y su asignación a un cliente. */
class CustomerTypeController extends Controller
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => CustomerType::query()->orderBy('name')->get()->map(fn (CustomerType $t) => $this->present($t))->all()]);
    }

    public function store(CustomerTypeRequest $request): JsonResponse
    {
        $type = CustomerType::create(['name' => $request->validated('name'), 'active' => $request->validated('active') ?? true]);
        $this->audit->record('customer_types.created', $request->user(), auditable: $type, new: $this->present($type));

        return response()->json(['data' => $this->present($type)], 201);
    }

    public function update(CustomerTypeRequest $request, CustomerType $customerType): JsonResponse
    {
        $old = $this->present($customerType);
        $customerType->update($request->validated());
        if ($customerType->wasChanged()) {
            $this->audit->record('customer_types.updated', $request->user(), auditable: $customerType, old: $old, new: $this->present($customerType));
        }

        return response()->json(['data' => $this->present($customerType)]);
    }

    public function assign(CustomerAssignTypeRequest $request): JsonResponse
    {
        $customer = Customer::query()->where('company_id', $request->validated('company_id'))->where('code', $request->validated('code'))->first();
        abort_if($customer === null, 404, 'Cliente no encontrado.');

        $old = $customer->customer_type_id;
        $customer->update(['customer_type_id' => $request->validated('customer_type_id')]);
        if ($customer->wasChanged('customer_type_id')) {
            $this->audit->record('customers.type_updated', $request->user(), auditable: $customer, old: ['customer_type_id' => $old], new: ['customer_type_id' => $customer->customer_type_id], companyId: $customer->company_id);
        }

        return response()->json(['data' => ['customer_type_id' => $customer->customer_type_id]]);
    }

    /** @return array{id: int, name: string, active: bool} */
    private function present(CustomerType $t): array
    {
        return ['id' => $t->id, 'name' => $t->name, 'active' => $t->active];
    }
}
