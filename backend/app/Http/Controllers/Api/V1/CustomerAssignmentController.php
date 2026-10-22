<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\CustomerAssignmentRequest;
use App\Http\Requests\CustomerDataRequest;
use App\Http\Requests\OperatorRequest;
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

        return response()->json(['data' => $query->get()->map(fn (Operator $o) => $this->presentOperator($o))->all()]);
    }

    public function supervisors(): JsonResponse
    {
        $users = User::query()->whereHas('role', fn ($q) => $q->where('slug', Role::SUPERVISOR_VENTAS))->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name])->all()]);
    }

    /** Usuarios con rol Vendedor que se pueden vincular a un vendedor del catálogo. */
    public function sellerUsers(): JsonResponse
    {
        $users = User::query()->whereHas('role', fn ($q) => $q->where('slug', Role::VENDEDOR))->where('is_active', true)->orderBy('name')->get();

        return response()->json(['data' => $users->map(fn (User $u) => ['id' => $u->id, 'name' => $u->name, 'username' => $u->username])->all()]);
    }

    public function storeSeller(SellerUpdateRequest $request): JsonResponse
    {
        $data = $request->validated();
        $isDefault = (bool) ($data['is_default'] ?? false);
        if ($isDefault) {
            Seller::where('branch_id', $data['branch_id'])->update(['is_default' => false]);
        }
        $seller = Seller::create($data + ['normalized_name' => Seller::normalize($data['name'])]);
        $this->audit->record('sellers.created', $request->user(), auditable: $seller, new: $seller->only(['name', 'branch_id', 'is_default', 'supervisor_id', 'user_id']), companyId: $seller->company_id);

        return response()->json(['data' => $this->present($seller->load(['branch', 'supervisor']))], 201);
    }

    public function updateSeller(SellerUpdateRequest $request, Seller $seller): JsonResponse
    {
        $full = $this->scope->fullCompanyIds($request->user());
        abort_if($full !== null && ! in_array($seller->company_id, $full, true), 404, 'Vendedor no encontrado.');

        $fields = ['name', 'is_active', 'branch_id', 'is_default', 'supervisor_id', 'user_id'];
        $before = $seller->only($fields);
        $data = $request->validated();
        if (isset($data['name'])) {
            $data['normalized_name'] = Seller::normalize($data['name']);
        }
        if (($data['is_default'] ?? false) === true) {
            // Una sola «Tienda» por sucursal: la anterior deja de serlo.
            Seller::where('branch_id', $data['branch_id'] ?? $seller->branch_id)->where('id', '!=', $seller->id)->update(['is_default' => false]);
        }
        $seller->update($data);
        if ($seller->wasChanged($fields)) {
            $this->audit->record('sellers.updated', $request->user(), auditable: $seller, old: $before, new: $seller->only($fields), companyId: $seller->company_id);
        }

        return response()->json(['data' => $this->present($seller->load(['branch', 'supervisor']))]);
    }

    public function storeOperator(OperatorRequest $request): JsonResponse
    {
        $data = $request->validated();
        $operator = Operator::create($data + ['normalized_name' => Operator::normalize($data['name'])]);
        $this->audit->record('operators.created', $request->user(), auditable: $operator, new: $operator->only(['name', 'is_active']), companyId: $operator->company_id);

        return response()->json(['data' => $this->presentOperator($operator)], 201);
    }

    public function updateOperator(OperatorRequest $request, Operator $operator): JsonResponse
    {
        $full = $this->scope->fullCompanyIds($request->user());
        abort_if($full !== null && ! in_array($operator->company_id, $full, true), 404, 'Facturador no encontrado.');

        $before = $operator->only(['name', 'is_active']);
        $data = $request->validated();
        if (isset($data['name'])) {
            $data['normalized_name'] = Operator::normalize($data['name']);
        }
        $operator->update($data);
        if ($operator->wasChanged(['name', 'is_active'])) {
            $this->audit->record('operators.updated', $request->user(), auditable: $operator, old: $before, new: $operator->only(['name', 'is_active']), companyId: $operator->company_id);
        }

        return response()->json(['data' => $this->presentOperator($operator)]);
    }

    /** Edita los datos generales de un cliente. Un cliente creado desde ventas pasa a «manual». */
    public function updateCustomer(CustomerDataRequest $request): JsonResponse
    {
        $customer = Customer::query()->where('company_id', $request->validated('company_id'))->where('code', $request->validated('code'))->first();
        abort_if($customer === null, 404, 'Cliente no encontrado.');

        $fields = ['name', 'trade_name', 'contact_name', 'phone', 'whatsapp', 'email', 'address', 'branch_id'];
        $before = $customer->only($fields);
        $data = $request->safe()->only($fields);
        $customer->fill($data);
        if ($customer->isDirty() && $customer->source === 'sales') {
            $customer->source = 'manual';
        }
        $customer->save();
        if ($customer->wasChanged($fields)) {
            $this->audit->record('customers.updated', $request->user(), auditable: $customer, old: $before, new: $customer->only($fields), companyId: $customer->company_id);
        }

        return response()->json(['data' => $customer->only($fields)]);
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

    /** @return array{id: int, name: string, company_id: int, is_active: bool} */
    private function presentOperator(Operator $o): array
    {
        return ['id' => $o->id, 'name' => $o->name, 'company_id' => $o->company_id, 'is_active' => $o->is_active];
    }

    /** @return array<string, mixed> */
    private function present(Seller $s): array
    {
        return [
            'id' => $s->id, 'name' => $s->name, 'company_id' => $s->company_id, 'is_active' => $s->is_active,
            'branch_id' => $s->branch_id, 'branch' => $s->branch?->name, 'is_default' => $s->is_default, 'user_id' => $s->user_id,
            'supervisor' => $s->supervisor ? ['id' => $s->supervisor->id, 'name' => $s->supervisor->name] : null,
        ];
    }
}
