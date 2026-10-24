<?php

declare(strict_types=1);

namespace App\Services\Reports\Output;

use Dompdf\Dompdf;
use Dompdf\Options;

/** Un reporte como PDF apaisado: contexto, avisos, cifras clave y una tabla por sección, con el nivel de cada semáforo escrito. */
class ReportPdf
{
    public function build(ReportDocument $document, string $generatedBy): string
    {
        $options = new Options;
        $options->set('isRemoteEnabled', false);   // el contenido viene de archivos importados: nunca se descargan recursos externos
        $options->set('isPhpEnabled', false);
        $options->set('defaultFont', 'DejaVu Sans');

        $pdf = new Dompdf($options);
        $pdf->loadHtml(view('pdf.report', ['document' => $document, 'levels' => ReportNotes::levelLabels(), 'generatedBy' => $generatedBy, 'generatedAt' => now()->format('Y-m-d H:i')])->render());
        $pdf->setPaper('A4', 'landscape');
        $pdf->render();

        return (string) $pdf->output();
    }

    /** Cómo se escribe un valor en el PDF (coma decimal y punto de millares, como el resto de los PDF del sistema). */
    public static function format(mixed $value, string $kind): string
    {
        if ($value === null) {
            return '—';
        }
        if (! is_numeric($value) || is_string($value) && $kind === ReportColumn::TEXT) {
            return (string) $value;
        }
        $n = (float) $value;

        return match ($kind) {
            ReportColumn::MONEY => number_format($n, 2, ',', '.'),
            ReportColumn::PCT => number_format($n, 1, ',', '.').' %',
            ReportColumn::SIGNED_PCT => ($n > 0 ? '+' : '').number_format($n, 1, ',', '.').' %',
            ReportColumn::DECIMAL => number_format($n, 2, ',', '.'),
            ReportColumn::INT => number_format($n, 0, ',', '.'),
            default => (string) $value,
        };
    }
}
