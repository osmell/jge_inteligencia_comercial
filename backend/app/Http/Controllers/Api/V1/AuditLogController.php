<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $logs = AuditLog::query()
            ->when($request->query('event'), fn ($q, $event) => $q->where('event', $event))
            ->latest('id')
            ->paginate(50);

        return response()->json($logs);
    }
}
