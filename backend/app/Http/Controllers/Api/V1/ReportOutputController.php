<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReportEmailRequest;
use App\Http\Requests\ReportExportRequest;
use App\Services\Reports\Output\ReportCatalog;
use App\Services\Reports\Output\ReportEmailer;
use App\Services\Reports\Output\ReportFiles;
use App\Services\Reports\ReportAudit;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/** Descarga (Excel o PDF) y envío por correo de los reportes de evaluación. Mismo alcance y permiso que la pantalla; todo queda en Auditoría. */
class ReportOutputController extends Controller
{
    public function __construct(private readonly ReportCatalog $catalog, private readonly ReportFiles $files, private readonly ReportAudit $audit) {}

    public function export(ReportExportRequest $request, string $report): Response
    {
        $user = $request->user();
        $file = $this->files->render($this->catalog->document($report, $user, $request), $request->fileFormat(), $user, $request->from(), $request->to());
        $this->audit->exported($user, $report, $request->fileFormat(), $request->auditFilters());

        return response($file['data'], 200, [
            'Content-Type' => $file['mime'],
            'Content-Disposition' => 'attachment; filename="'.$file['name'].'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * @response array{data: array{results: list<array{to: string, status: string, error: string|null}>, sent: int, failed: int}}
     */
    public function email(ReportEmailRequest $request, string $report, ReportEmailer $emailer): JsonResponse
    {
        $user = $request->user();
        $results = $emailer->send(
            $user, $report, $this->catalog->document($report, $user, $request), $request->fileFormat(), $request->recipients(), $request->note(),
            $request->from(), $request->to(), $request->auditFilters(),
        );

        return response()->json(['data' => [
            'results' => $results,
            'sent' => count(array_filter($results, fn (array $r): bool => $r['status'] === 'sent')),
            'failed' => count(array_filter($results, fn (array $r): bool => $r['status'] !== 'sent')),
        ]]);
    }
}
