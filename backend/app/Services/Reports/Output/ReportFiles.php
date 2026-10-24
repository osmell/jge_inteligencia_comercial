<?php

declare(strict_types=1);

namespace App\Services\Reports\Output;

use App\Models\User;
use Carbon\CarbonImmutable;

/** El archivo de un reporte en el formato pedido: su contenido, su nombre y su tipo. */
class ReportFiles
{
    public const FORMATS = ['xlsx', 'pdf'];

    private const MIME = [
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'pdf' => 'application/pdf',
    ];

    public function __construct(private readonly ReportWorkbook $workbook, private readonly ReportPdf $pdf) {}

    /** @return array{data: string, name: string, mime: string} */
    public function render(ReportDocument $document, string $format, User $by, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($format === 'xlsx') {
            $path = $this->workbook->build($document, $by->name);
            $data = (string) file_get_contents($path);
            @unlink($path);
            @unlink(substr($path, 0, -5));   // tempnam crea además el archivo sin extensión
        } else {
            $data = $this->pdf->build($document, $by->name);
        }

        return ['data' => $data, 'name' => "{$document->slug}-{$from->toDateString()}_{$to->toDateString()}.{$format}", 'mime' => self::MIME[$format]];
    }
}
