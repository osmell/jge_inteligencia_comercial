<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Services\DataScope;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request, DataScope $scope): JsonResponse
    {
        // Quien no es Administrador solo ve registros de sus compañías (los de acceso, sin compañía, quedan fuera).
        $companies = $scope->companyIds($request->user());

        $logs = AuditLog::query()
            ->when($companies !== null, fn ($q) => $q->whereIn('company_id', $companies))
            ->when($request->query('event'), fn ($q, $event) => $q->where('event', $event))
            ->when($request->query('username'), fn ($q, $u) => $q->where('username', mb_strtolower($u)))
            ->when($request->query('company_id'), fn ($q, $id) => $q->where('company_id', $id))
            ->latest('id')
            ->paginate(50);

        return response()->json($logs);
    }
}
