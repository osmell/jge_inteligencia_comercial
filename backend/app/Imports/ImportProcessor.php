<?php

declare(strict_types=1);

namespace App\Imports;

use App\Imports\Contracts\ImportType;
use App\Imports\Exceptions\UnreadableFile;
use Generator;
use RuntimeException;

/**
 * Lee el archivo y consolida las filas en documentos. No escribe en la base de datos.
 * Se usa tanto para validar como para importar (con `keepRows`).
 */
final class ImportProcessor
{
    /** @return array{groups: array<string, ImportGroup>, rows_total: int, rows_invalid: int, preview: list<array<string, mixed>>, header_ok: bool, preview_columns: list<string>} */
    public function process(string $path, ImportType $type, ErrorCollector $errors): array
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

                if ($row->skipped()) {
                    continue;
                }

                if ($parsed === null || $row->failed()) {
                    $rowsInvalid++;

                    continue;
                }

                $this->addToGroup($groups, $parsed, $labels, $errors);
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
            'preview_columns' => array_values($labels), // jsonb no conserva el orden de las claves
        ];
    }

    /**
     * Vuelve a leer el archivo y entrega las filas ya validadas, una a una, sin acumularlas en memoria.
     * Se usa al importar para escribir las líneas; si una fila dejara de ser válida (el archivo o la base
     * cambiaron durante la importación) se interrumpe y la transacción se revierte.
     *
     * @return Generator<int, ParsedRow>
     */
    public function stream(string $path, ImportType $type): Generator
    {
        $columns = $type->columns();
        $labels = [];
        foreach ($columns as $column) {
            $labels[$column->key] = $column->label();
        }

        $errors = new ErrorCollector(1);
        $map = null;

        foreach (SpreadsheetReader::rows($path, config('imports.'.$type->key().'.sheet')) as $number => $cells) {
            if (SpreadsheetReader::isBlank($cells)) {
                continue;
            }
            if ($map === null) {
                $map = HeaderMapper::map($cells, $columns, $errors, $number) ?? throw new RuntimeException('Los encabezados del archivo cambiaron durante la importación.');

                continue;
            }

            $values = [];
            foreach ($map as $key => $index) {
                $values[$key] = $cells[$index] ?? null;
            }

            $row = new ImportRow($number, $values, $errors, $labels);
            $parsed = $type->parseRow($row);
            if ($row->skipped()) {
                continue;
            }
            if ($parsed === null || $row->failed()) {
                throw new RuntimeException("La fila {$number} dejó de ser válida durante la importación.");
            }

            yield $parsed;
        }
    }

    /**
     * @param  array<string, ImportGroup>  $groups
     * @param  array<string, string>  $labels
     */
    private function addToGroup(array &$groups, ParsedRow $parsed, array $labels, ErrorCollector $errors): void
    {
        $group = $groups[$parsed->groupKey] ??= new ImportGroup($parsed->groupKey, $this->pooled($parsed->groupFields), $parsed->rowNumber);

        // Archivos maestros (clientes, productos): una fila por registro, sin líneas; el mensaje habla del código, no de «documentos».
        $master = $parsed->line === [];
        $code = $parsed->groupFields['code'] ?? $parsed->groupKey;

        // Todas las líneas de un documento deben coincidir en los datos del documento.
        foreach ($group->fields as $field => $expected) {
            $actual = $parsed->groupFields[$field] ?? null;
            if ($actual !== $expected) {
                $label = $labels[$field] ?? $field;
                $errors->error(
                    'inconsistent_group',
                    $master
                        ? "El código {$code} aparece en varias filas con «{$label}» distinto (la primera está en la fila {$group->firstRow})."
                        : "El documento {$parsed->groupKey} tiene «{$label}» distinto al de su primera línea (fila {$group->firstRow}).",
                    $parsed->rowNumber,
                    $label,
                    $actual,
                );
            }
        }

        $hash = hash('xxh128', json_encode($parsed->line, JSON_THROW_ON_ERROR), true);
        if (in_array($hash, $group->lineHashes, true)) {
            $errors->warning('duplicate_row', $master ? "Fila repetida con los mismos datos para el código {$code}." : "Línea idéntica repetida dentro del documento {$parsed->groupKey}.", $parsed->rowNumber);
        }

        $group->lineHashes[] = $hash;
    }

    /**
     * Comparte una sola copia de cada texto repetido (cliente, vendedor, sucursal…) entre documentos: con
     * cientos de miles de documentos reduce mucho la memoria.
     *
     * @var array<string, string>
     */
    private array $pool = [];

    /**
     * @param  array<string, string|null>  $fields
     * @return array<string, string|null>
     */
    private function pooled(array $fields): array
    {
        return array_map(fn (?string $v) => $v === null ? null : ($this->pool[$v] ??= $v), $fields);
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
