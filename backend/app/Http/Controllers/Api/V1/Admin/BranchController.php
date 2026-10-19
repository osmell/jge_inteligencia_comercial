<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\BranchRequest;
use App\Models\Branch;
use Illuminate\Http\JsonResponse;

/** Las sucursales no se eliminan: se desactivan con is_active. */
class BranchController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Branch::orderBy('company_id')->orderBy('name')->get()]);
    }

    public function store(BranchRequest $request): JsonResponse
    {
        $branch = Branch::create($request->validated());

        return response()->json(['data' => $branch], 201);
    }

    public function show(Branch $branch): JsonResponse
    {
        return response()->json(['data' => $branch]);
    }

    public function update(BranchRequest $request, Branch $branch): JsonResponse
    {
        $branch->update($request->validated());

        return response()->json(['data' => $branch]);
    }
}
