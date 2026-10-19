<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CompanyRequest;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

/** Las compañías no se eliminan: se desactivan con is_active. */
class CompanyController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json(['data' => Company::orderBy('name')->get()]);
    }

    public function store(CompanyRequest $request): JsonResponse
    {
        $company = Company::create($request->validated());

        return response()->json(['data' => $company], 201);
    }

    public function show(Company $company): JsonResponse
    {
        return response()->json(['data' => $company]);
    }

    public function update(CompanyRequest $request, Company $company): JsonResponse
    {
        $company->update($request->validated());

        return response()->json(['data' => $company]);
    }
}
