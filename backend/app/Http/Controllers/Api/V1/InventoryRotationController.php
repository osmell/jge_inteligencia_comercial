<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\InventoryRotation;
use App\Services\Reports\CsvSafe;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/** Reporte de rotación, cobertura, mínimos y máximos, y su exportación. */
class InventoryRotationController extends Controller
{
    public function __construct(private readonly InventoryRotation $rotation, private readonly AuditLogger $audit) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $this->filters($request);
        $result = $this->rotation->report($request->user(), $filters);
        $page = max(1, (int) $request->integer('page'));
        $slice = array_slice($result['rows'], ($page - 1) * InventoryRotation::PAGE_SIZE, InventoryRotation::PAGE_SIZE + 1);

        return response()->json([
            'data' => array_slice($slice, 0, InventoryRotation::PAGE_SIZE),
            'meta' => ['page' => $page, 'has_more' => count($slice) > InventoryRotation::PAGE_SIZE, 'total' => count($result['rows']), 'counts' => $result['counts'], 'params' => $result['params']],
        ]);
    }

    public function export(Request $request): Response
    {
        $filters = $this->filters($request);
        $result = $this->rotation->report($request->user(), $filters);
        $costs = isset($result['rows'][0]['value']) || $request->user()->hasPermission('inventory.costs');
        $this->audit->record('inventory.rotation_exported', $request->user(), new: ['filters' => $filters, 'rows' => count($result['rows'])]);
        $labels = ['out_of_stock' => 'Agotado', 'below_min' => 'Bajo el mínimo', 'no_rotation' => 'Sin rotación', 'above_max' => 'Sobre el máximo', 'ok' => 'Normal'];

        return response()->streamDownload(function () use ($result, $costs, $labels) {
            $out = fopen('php://output', 'w') ?: throw new \RuntimeException('No se pudo abrir la salida de la descarga.');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Código', 'Producto', 'Sucursal', 'Existencia', 'Vendido (ventana)', 'Demanda diaria', 'Cobertura (días)', 'Rotación', 'Mínimo', 'Máximo', 'Sugerido a reponer', 'Última venta', 'Estado', ...($costs ? ['Valor'] : [])]);
            foreach ($result['rows'] as $r) {
                fputcsv($out, [
                    CsvSafe::value($r['product']['code']), CsvSafe::value($r['product']['name']), CsvSafe::value($r['branch']), $r['stock'], $r['sold'], $r['daily_demand'],
                    $r['coverage_days'], $r['rotation'], $r['min'], $r['max'], $r['suggested'], $r['last_sale'], $labels[$r['status']], ...($costs ? [$r['value'] ?? ''] : []),
                ]);
            }
            fclose($out);
        }, 'rotacion-de-inventario-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** @return array{company_id?: int|null, branch_id?: int|null, q?: string|null, status?: string|null} */
    private function filters(Request $request): array
    {
        return $request->validate([
            'company_id' => ['nullable', 'integer', 'min:1'], 'branch_id' => ['nullable', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::in(['all', ...InventoryRotation::STATUSES])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000'],
        ]);
    }
}
