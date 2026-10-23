<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportContext;
use Illuminate\Http\JsonResponse;

/** Opciones de los filtros de los reportes de evaluación. */
class ReportContextController extends Controller
{
    public function show(ReportContext $context): JsonResponse
    {
        return response()->json(['data' => $context->for()]);
    }
}
