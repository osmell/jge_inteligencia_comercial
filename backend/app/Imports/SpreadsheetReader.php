<?php

declare(strict_types=1);

namespace App\Imports;

use App\Imports\Exceptions\UnreadableFile;
use Generator;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/** Lee un .xlsx fila por fila (streaming) conservando el número de fila real de Excel. */
final class SpreadsheetReader
{
    /**
     * @return Generator<int, list<mixed>> número de fila (desde 1) => valores de las celdas
     *
     * @throws UnreadableFile
     */
    public static function rows(string $path, ?string $sheetName = null): Generator
    {
        $reader = new Reader(new Options(SHOULD_PRESERVE_EMPTY_ROWS: true));

        try {
            $reader->open($path);
        } catch (Throwable $e) {
            throw new UnreadableFile('El archivo no es un Excel (.xlsx) válido o está dañado.', 0, $e);
        }

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                if ($sheetName !== null && $sheet->getName() !== $sheetName) {
                    continue;
                }

                $number = 0;
                foreach ($sheet->getRowIterator() as $row) {
                    $number++;
                    yield $number => $row->toArray();
                }

                return; // solo una hoja
            }

            if ($sheetName !== null) {
                throw new UnreadableFile("No existe la hoja «{$sheetName}» en el archivo.");
            }
        } catch (UnreadableFile $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new UnreadableFile('No se pudo leer el contenido del archivo: está dañado o tiene un formato no soportado.', 0, $e);
        } finally {
            $reader->close();
        }
    }

    /** @param  list<mixed>  $cells */
    public static function isBlank(array $cells): bool
    {
        foreach ($cells as $cell) {
            if ($cell !== null && ! (is_string($cell) && trim($cell) === '')) {
                return false;
            }
        }

        return true;
    }
}
