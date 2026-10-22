<?php

declare(strict_types=1);

namespace App\Imports;

use App\Imports\Contracts\ImportType;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/** Genera la plantilla .xlsx en blanco de un tipo: hoja de datos con encabezados y hoja «Instrucciones». */
final class TemplateBuilder
{
    /** @return string ruta de un archivo temporal */
    public function build(ImportType $type, string $sheetName): string
    {
        $path = tempnam(sys_get_temp_dir(), 'tpl').'.xlsx';
        $bold = (new Style)->withFontBold(true);

        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName($sheetName);
        $writer->addRow(Row::fromValuesWithStyle(array_map(fn (ColumnDefinition $c) => $c->label(), $type->columns()), $bold));

        $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        $writer->addRow(Row::fromValuesWithStyle(['Columna', 'Obligatoria', 'Descripción'], $bold));
        foreach ($type->columns() as $column) {
            $writer->addRow(Row::fromValues([$column->label(), $column->required ? 'Sí' : 'No', $column->hint ?? '']));
        }
        $writer->addRow(Row::fromValues([]));
        $writer->addRow(Row::fromValues(['Se importa solo la primera hoja. No borre ni renombre los encabezados.']));

        $writer->close();

        return $path;
    }
}
