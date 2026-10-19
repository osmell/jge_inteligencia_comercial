<?php

namespace App\Imports;

use App\Imports\Contracts\ImportType;
use App\Imports\Exceptions\UnreadableFile;

/**
 * Lee el archivo y consolida las filas en documentos. No escribe en la base de datos.
 * Se usa tanto para validar como para importar (con `keepRows`).
 */
final class ImportProcessor
{
    /** @return array{groups: array<string, ImportGroup>, rows_total: int, rows_invalid: int, preview: list<array<string, mixed>>, header_ok: bool} */
    public function process(string $path, ImportType $type, ErrorCollector $errors, bool $keepRows = false): array
    {
        $columns = $type->columns();
        $labels = [];
        foreach ($columns as $column) {
            $labels[$column->key] = $column->label();
        }

        $map = null;
        $headerSeen = false;
        $groups = [];
        $rowsTotal = 0;
        $rowsInvalid = 0;
        $preview = [];
        $maxRows = (int) config('imports.max_rows');
        $previewRows = (int) config('imports.preview_rows');

        try {
            foreach (SpreadsheetReader::rows($path, config('imports.'.$type->key().'.sheet')) as $number => $cells) {
                if (SpreadsheetReader::isBlank($cells)) {
                    continue;
                }

                if (! $headerSeen) {
                    $headerSeen = true;
                    $map = HeaderMapper::map($cells, $columns, $errors, $number);
                    if ($map === null) {
                        break; // sin encabezados válidos no se leen las filas
                    }

                    continue;
                }

                if (++$rowsTotal > $maxRows) {
                    $errors->error('too_many_rows', "El archivo supera el máximo de {$maxRows} filas de datos.");
                    $rowsTotal--;
                    break;
                }

                $values = [];
                foreach ($map as $key => $index) {
                    $values[$key] = $cells[$index] ?? null;
                }

                if (count($preview) < $previewRows) {
                    $preview[] = ['row' => $number, 'values' => self::previewValues($values, $labels)];
                }

                $row = new ImportRow($number, $values, $errors, $labels);
                $parsed = $type->parseRow($row);

                if ($parsed === null || $row->failed()) {
                    $rowsInvalid++;

                    continue;
                }

                $this->addToGroup($groups, $parsed, $labels, $errors, $keepRows);
            }
        } catch (UnreadableFile $e) {
            $errors->error('unreadable_file', $e->getMessage());
        }

        if (! $headerSeen && ! $errors->hasErrors()) {
            $errors->error('empty_file', 'El archivo está vacío.');
        } elseif ($map !== null && $rowsTotal === 0 && ! $errors->hasErrors()) {
            $errors->error('no_data_rows', 'El archivo tiene encabezados pero ninguna fila de datos.');
        }

        return [
            'groups' => $groups,
            'rows_total' => $rowsTotal,
            'rows_invalid' => $rowsInvalid,
            'preview' => $preview,
            'header_ok' => $map !== null,
        ];
    }

    /**
     * @param  array<string, ImportGroup>  $groups
     * @param  array<string, string>  $labels
     */
    private function addToGroup(array &$groups, ParsedRow $parsed, array $labels, ErrorCollector $errors, bool $keepRows): void
    {
        $group = $groups[$parsed->groupKey] ??= new ImportGroup($parsed->groupKey, $parsed->groupFields, $parsed->rowNumber);

        // Todas las líneas de un documento deben coincidir en los datos del documento.
        foreach ($group->fields as $field => $expected) {
            $actual = $parsed->groupFields[$field] ?? null;
            if ($actual !== $expected) {
                $label = $labels[$field] ?? $field;
                $errors->error(
                    'inconsistent_group',
                    "El documento {$parsed->groupKey} tiene «{$label}» distinto al de su primera línea (fila {$group->firstRow}).",
                    $parsed->rowNumber,
                    $label,
                    $actual,
                );
            }
        }

        $hash = hash('sha256', json_encode($parsed->line, JSON_THROW_ON_ERROR));
        if (in_array($hash, $group->lineHashes, true)) {
            $errors->warning('duplicate_row', "Línea idéntica repetida dentro del documento {$parsed->groupKey}.", $parsed->rowNumber);
        }

        $group->rowNumbers[] = $parsed->rowNumber;
        $group->lineHashes[] = $hash;
        if ($keepRows) {
            $group->rows[] = $parsed;
        }
    }

    /**
     * @param  array<string, mixed>  $values
     * @param  array<string, string>  $labels
     * @return array<string, string|null>
     */
    private static function previewValues(array $values, array $labels): array
    {
        $out = [];
        foreach ($labels as $key => $label) {
            $out[$label] = ErrorCollector::display($values[$key] ?? null);
        }

        return $out;
    }
}
