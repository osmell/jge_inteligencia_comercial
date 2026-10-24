<?php

declare(strict_types=1);

namespace App\Services\Reports\Output;

use App\Services\Reports\CsvSafe;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * Un reporte como Excel: una hoja «Resumen» (contexto, avisos y cifras clave) y una hoja por tabla. Los importes y porcentajes salen como
 * números —con formato— para poder sumarlos y ordenarlos; cada columna con semáforo lleva al lado su nivel escrito.
 */
class ReportWorkbook
{
    private const FORMATS = [ReportColumn::MONEY => '#,##0.00', ReportColumn::PCT => '0.0', ReportColumn::SIGNED_PCT => '+0.0;-0.0;0.0', ReportColumn::INT => '#,##0', ReportColumn::DECIMAL => '0.00'];

    /** @return string ruta de un archivo temporal (el llamador lo elimina) */
    public function build(ReportDocument $document, string $generatedBy): string
    {
        $path = tempnam(sys_get_temp_dir(), 'rpt').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $bold = (new Style)->withFontBold(true);

        $writer->getCurrentSheet()->setName('Resumen');
        $writer->addRow(Row::fromValuesWithStyle([$document->title], $bold));
        foreach ($document->lines as $line) {
            $writer->addRow(Row::fromValues([CsvSafe::value($line)]));
        }
        $writer->addRow(Row::fromValues(['Generado por', CsvSafe::value($generatedBy), 'Generado el', now()->format('Y-m-d H:i')]));
        $writer->addRow(Row::fromValues([]));
        foreach ($document->notes as $note) {
            $writer->addRow(Row::fromValues(['Nota', $note]));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValuesWithStyle(['Cifra', 'Valor'], $bold));
        foreach ($document->stats as $stat) {
            $writer->addRow(new Row([Cell::fromValue($stat['label']), $this->cell($stat['value'], $stat['kind'])]));
        }

        $used = ['Resumen'];
        foreach ($document->sections as $section) {
            $writer->addNewSheetAndMakeItCurrent()->setName($this->sheetName($section->title, $used));
            $headers = [];
            foreach ($section->columns as $column) {
                $headers[] = $column->label;
                if ($column->leveled) {
                    $headers[] = $column->label.' · nivel';
                }
            }
            $writer->addRow(Row::fromValuesWithStyle($headers, $bold));
            foreach ($section->rows as $row) {
                $cells = [];
                foreach ($section->columns as $i => $column) {
                    $value = $row[$i] ?? null;
                    if ($column->leveled) {
                        $level = is_array($value) ? ($value['level'] ?? 'none') : 'none';
                        $cells[] = $this->cell(is_array($value) ? $value['value'] : $value, $column->kind);
                        $cells[] = Cell::fromValue(ReportNotes::levelLabels()[$level] ?? 'Sin dato');
                    } else {
                        $cells[] = $this->cell($value, $column->kind);
                    }
                }
                $writer->addRow(new Row($cells));
            }
            if ($section->note !== null) {
                $writer->addRow(Row::fromValues([]));
                $writer->addRow(Row::fromValues(['Nota', $section->note]));
            }
        }

        $writer->close();

        return $path;
    }

    private function cell(mixed $value, string $kind): Cell
    {
        if ($value === null) {
            return Cell::fromValue('—');
        }
        if (is_numeric($value) && ! is_string($value)) {
            $format = self::FORMATS[$kind] ?? null;

            return Cell::fromValue($value, $format === null ? null : (new Style)->withFormat($format));
        }

        return Cell::fromValue(CsvSafe::value((string) $value));
    }

    /**
     * Excel limita el nombre de la hoja a 31 caracteres, sin ciertos símbolos y sin repetirse.
     *
     * @param  list<string>  $used
     */
    private function sheetName(string $title, array &$used): string
    {
        $base = mb_substr(trim((string) preg_replace('/[\\\\\/?*\[\]:]/', ' ', $title)), 0, 31);
        $name = $base;
        for ($i = 2; in_array($name, $used, true); $i++) {
            $name = mb_substr($base, 0, 28).' '.$i;
        }
        $used[] = $name;

        return $name;
    }
}
