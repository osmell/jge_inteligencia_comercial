<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeInterface;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

class Xlsx
{
    /** Crea un .xlsx temporal con las filas dadas y devuelve su ruta. Una fila `[]` queda en blanco. */
    public static function make(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xlsx').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        foreach ($rows as $row) {
            $dateStyle = (new Style)->withFormat('yyyy-mm-dd'); // como en un Excel real, las fechas llevan formato de fecha
            $cells = array_map(fn ($v) => $v instanceof DateTimeInterface ? Cell::fromValue($v, $dateStyle) : Cell::fromValue($v), $row === [] ? [null] : $row);
            $writer->addRow(new Row($cells));
        }
        $writer->close();

        return $path;
    }
}
