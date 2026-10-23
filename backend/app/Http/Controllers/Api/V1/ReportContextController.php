<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Reports\ReportContext;
use Illuminate\Http\JsonResponse;

/** Opciones de los filtros de los reportes de evaluación. */
class ReportContextController extends Controller
{
    /**
     * @response array{data: array{companies: list<array{id: int, code: string, name: string}>, branches: list<array{id: int, company_id: int, code: string, name: string}>, sellers: list<array{id: int, company_id: int, branch_id: int|null, name: string, is_active: bool}>, operators: list<array{id: int, company_id: int, name: string, is_active: bool}>, comparison_modes: list<string>, thresholds: array<string, array{higher_is_better: bool, warn: int, bad: int}>}}
     */
    public function show(ReportContext $context): JsonResponse
    {
        return response()->json(['data' => $context->for()]);
    }
}
