<?php

declare(strict_types=1);

namespace App\Imports;

use Illuminate\Support\Str;

/** Asocia los encabezados del archivo con las columnas del tipo, tolerando mayúsculas, tildes, espacios y signos. */
final class HeaderMapper
{
    /**
     * @param  list<mixed>  $headerCells
     * @param  list<ColumnDefinition>  $columns
     * @return array<string, int>|null clave de columna => posición; null si hay errores en los encabezados
     */
    public static function map(array $headerCells, array $columns, ErrorCollector $errors, int $headerRow = 1): ?array
    {
        $byAlias = [];
        foreach ($columns as $column) {
            foreach ($column->aliases as $alias) {
                $byAlias[self::normalize($alias)] = $column;
            }
        }

        $map = [];
        $ok = true;

        foreach ($headerCells as $index => $cell) {
            $text = is_scalar($cell) ? trim((string) $cell) : '';
            if ($text === '') {
                continue;
            }

            $column = $byAlias[self::normalize($text)] ?? null;
            if ($column === null) {
                $errors->warning('unknown_column', "La columna «{$text}» no se reconoce y se ignorará.", $headerRow, $text);

                continue;
            }
            if (isset($map[$column->key])) {
                $errors->error('duplicate_column', "La columna «{$column->label()}» aparece más de una vez.", $headerRow, $text);
                $ok = false;

                continue;
            }
            $map[$column->key] = $index;
        }

        foreach ($columns as $column) {
            if ($column->required && ! isset($map[$column->key])) {
                $errors->error('missing_column', "Falta la columna obligatoria «{$column->label()}».", $headerRow, $column->label());
                $ok = false;
            }
        }

        return $ok ? $map : null;
    }

    public static function normalize(string $header): string
    {
        return preg_replace('/[^a-z0-9]+/', '', Str::lower(Str::ascii($header)));
    }
}
