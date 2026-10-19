<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Company;
use Illuminate\Http\JsonResponse;

/** Listados de compañías y sucursales dentro del ámbito del usuario (los global scopes filtran). */
class OrganizationController extends Controller
{
    public function companies(): JsonResponse
    {
        return response()->json(['data' => Company::orderBy('name')->get(['id', 'code', 'name', 'is_active'])]);
    }

    public function branches(): JsonResponse
    {
        return response()->json(['data' => Branch::orderBy('name')->get(['id', 'company_id', 'code', 'name', 'is_active'])]);
    }
}
