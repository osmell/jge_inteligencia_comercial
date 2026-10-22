<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Models\Branch;
use App\Models\Seller;
use App\Services\BranchRelocation;
use App\Services\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Las sucursales no se eliminan: se desactivan con is_active. */
class BranchController extends Controller
{
    public function __construct(private readonly BranchRelocation $relocation, private readonly DataScope $scope) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => Branch::orderBy('company_id')->orderBy('name')->get()]);
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $branch = Branch::create($request->validated());
        Seller::ensureStoreSeller($branch);

        return response()->json(['data' => $branch], 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        return response()->json(['data' => $branch]);
    }

    public function update(BranchRequest $request, Branch $branch): JsonResponse
    {
        $data = $request->validated();
        $target = isset($data['company_id']) ? (int) $data['company_id'] : $branch->company_id;
        unset($data['company_id']);
        if ($target !== $branch->company_id) {
            $this->assertMovable($request, $branch);
        }
        $branch->update($data);
        if ($target !== $branch->company_id) {
            $this->relocation->move($branch, $target);
        }

        return response()->json(['data' => $branch->refresh()]);
    }

    /** ¿Se puede mover esta sucursal a otra compañía? Si no, qué datos relacionados lo impiden. */
    public function relocation(Request $request, Branch $branch): JsonResponse
    {
        $blockers = $this->relocation->blockers($branch);

        return response()->json(['data' => ['can_move' => $blockers === [] && $this->inScope($request, $branch), 'blockers' => $blockers]]);
    }

    private function inScope(Request $request, Branch $branch): bool
    {
        $allowed = $this->scope->fullCompanyIds($request->user());

        return $allowed === null || in_array($branch->company_id, $allowed, true);
    }

    private function assertMovable(Request $request, Branch $branch): void
    {
        if (! $this->inScope($request, $branch)) {
            throw ValidationException::withMessages(['company_id' => 'No tienes acceso completo a la compañía actual de la sucursal.']);
        }
        $blockers = $this->relocation->blockers($branch);
        if ($blockers !== []) {
            $list = implode(', ', array_map(fn (array $b) => $b['count'].' '.$b['label'], $blockers));
            throw ValidationException::withMessages(['company_id' => "No se puede mover la sucursal a otra compañía porque tiene datos relacionados: $list. Desactívala y crea una nueva en la otra compañía."]);
        }
    }
}
