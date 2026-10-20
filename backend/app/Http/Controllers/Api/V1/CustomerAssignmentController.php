<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerAssignmentRequest;
use App\Http\Requests\SellerUpdateRequest;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Catálogo de vendedores (tienda por defecto y supervisor), facturadores y asignación de vendedor/facturador a un cliente.
 * El supervisor de un cliente es el supervisor de su vendedor. Un cliente sin vendedor se atribuye a la «Tienda» de su sucursal.
 */
class CustomerAssignmentController extends Controller
{
    public function __construct(private readonly DataScope $scope, private readonly AuditLogger $audit) {}

    public function sellers(Request $request): JsonResponse
    {
        $query = Seller::query()->with(['branch', 'supervisor'])->orderBy('company_id')->orderBy('name');
        $companies = $this->scope->companyIds($request->user());
        if ($companies !== null) {
            $query->whereIn('company_id', $companies);
        }
        if ($request->integer('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        return response()->json(['data' => $query->get()->map(fn (Seller $s) => $this->present($s))->all()]);
    }

    public function operators(Request $request): JsonResponse
    {
        $query = Operator::query()->orderBy('name');
        $companies = $this->scope->companyIds($request->user());
        if ($companies !== null) {
            $query->whereIn('company_id', $companies);
        }
        if ($request->integer('company_id')) {
            $query->where('company_id', $request->integer('company_id'));
        }

        return response()->json(['data' => $query->get()->map(fn (Operator $o) => ['id' => $o->id, 'name' => $o->name, 'company_id' => $o->company_id])->all()]);
    }

    public function supervisors(): JsonResponse
    {
        $users = User::query()->whereHas('role', fn ($q) => $q->where('slug', Role::SUPERVISOR_VENTAS))->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all()]);
    }

    public function updateSeller(SellerUpdateRequest $request, Seller $seller): JsonResponse
    {
        $full = $this->scope->fullCompanyIds($request->user());
        abort_if($full !== null && ! in_array($seller->company_id, $full, true), 404, 'Vendedor no encontrado.');

        $before = $seller->only(['branch_id', 'is_default', 'supervisor_id']);
        $data = $request->validated();
        if (($data['is_default'] ?? false) === true) {
            // Una sola «Tienda» por sucursal: la anterior deja de serlo.
            Seller::where('branch_id', $data['branch_id'] ?? $seller->branch_id)->where('id', '!=', $seller->id)->update(['is_default' => false]);
        }
        $seller->update($data);
        if ($seller->wasChanged(['branch_id', 'is_default', 'supervisor_id'])) {
            $this->audit->record('sellers.updated', $request->user(), auditable: $seller, old: $before, new: $seller->only(['branch_id', 'is_default', 'supervisor_id']), companyId: $seller->company_id);
        }

        return response()->json(['data' => $this->present($seller->load(['branch', 'supervisor']))]);
    }

    public function assign(CustomerAssignmentRequest $request): JsonResponse
    {
        $customer = Customer::query()->where('company_id', $request->validated('company_id'))->where('code', $request->validated('code'))->first();
        abort_if($customer === null, 404, 'Cliente no encontrado.');

        $before = $customer->only(['seller_id', 'operator_id']);
        $customer->update($request->safe()->only(['seller_id', 'operator_id']));
        if ($customer->wasChanged(['seller_id', 'operator_id'])) {
            $this->audit->record('customers.assignment_updated', $request->user(), auditable: $customer, old: $before, new: $customer->only(['seller_id', 'operator_id']), companyId: $customer->company_id);
        }

        return response()->json(['data' => $customer->only(['seller_id', 'operator_id'])]);
    }

    /** @return array<string, mixed> */
    private function present(Seller $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'company_id' => $s->company_id, 'is_active' => $s->is_active,
            'branch_id' => $s->branch_id, 'branch' => $s->branch?->name, 'is_default' => $s->is_default,
            'supervisor' => $s->supervisor ? ['id' => $s->supervisor->id, 'name' => $s->supervisor->name] : null,
        ];
    }
}
