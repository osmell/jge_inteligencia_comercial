<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\AuditLogger;
use App\Services\CreditPortfolio;
use App\Services\SalesReportExporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Antigüedad de cartera por cliente, su exportación y el resumen de crédito de un cliente. */
class CreditPortfolioController extends Controller
{
    private const ON_SCREEN = 300;

    public function __construct(private readonly CreditPortfolio $portfolio, private readonly AuditLogger $audit) {}

    public function aging(Request $request): JsonResponse
    {
        $aging = $this->portfolio->aging($this->companyId($request));

        return response()->json([
            'data' => ['buckets' => $aging['buckets'], 'totals' => $aging['totals'], 'customers' => array_slice($aging['customers'], 0, self::ON_SCREEN)],
            'meta' => ['customers_total' => count($aging['customers']), 'truncated' => count($aging['customers']) > self::ON_SCREEN],
        ]);
    }

    public function export(Request $request): Response
    {
        $companyId = $this->companyId($request);
        $aging = $this->portfolio->aging($companyId);
        $this->audit->record('credit.aging_exported', $request->user(), new: ['company_id' => $companyId, 'customers' => count($aging['customers'])]);

        return response()->streamDownload(function () use ($aging) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['Compañía', 'Código cliente', 'Cliente', ...array_map($this->bucketHeader(...), $aging['buckets']), 'Total', 'Facturas con saldo', 'Días de la más antigua']);
            foreach ($aging['customers'] as $c) {
                fputcsv($out, [SalesReportExporter::csvSafe($c['company']), SalesReportExporter::csvSafe($c['code']), SalesReportExporter::csvSafe($c['name']), ...array_map(fn ($b) => $c['buckets'][$b], $aging['buckets']), $c['total'], $c['invoices'], $c['oldest_days']]);
            }
            fputcsv($out, ['Total', '', '', ...array_map(fn ($b) => $aging['totals'][$b], $aging['buckets']), $aging['totals']['total'], '', '']);
            fclose($out);
        }, 'antiguedad-de-cartera-'.now()->toDateString().'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** Crédito de un cliente para su ficha: saldo por tramo y devoluciones vigentes. */
    public function customer(Request $request): JsonResponse
    {
        $data = $request->validate(['company_id' => ['required', 'integer', 'min:1'], 'code' => ['required', 'string', 'max:255']]);
        $aging = $this->portfolio->aging($data['company_id'], $data['code']);

        return response()->json(['data' => [
            'buckets' => $aging['buckets'], 'totals' => $aging['totals'], 'open_invoices' => $aging['customers'][0]['invoices'] ?? 0,
            'oldest_days' => $aging['customers'][0]['oldest_days'] ?? 0, 'returns' => $this->portfolio->returns($data['company_id'], $data['code']),
        ]]);
    }

    private function companyId(Request $request): ?int
    {
        $data = $request->validate(['company_id' => ['nullable', 'integer', 'min:1']]);

        return isset($data['company_id']) ? (int) $data['company_id'] : null;
    }

    private function bucketHeader(string $bucket): string
    {
        return match (true) {
            $bucket === 'current' => 'Al día',
            str_ends_with($bucket, '+') => 'Más de '.((int) $bucket - 1).' días',
            default => str_replace('-', ' a ', $bucket).' días',
        };
    }
}
