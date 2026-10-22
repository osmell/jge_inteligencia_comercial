<?php

declare(strict_types=1);

namespace App\Imports\Rules;

use App\Imports\ImportRow;
use DateTimeImmutable;

/** Reglas de validación de fila que se repetían en varios tipos de importación. */
final class RowRules
{
    /** Texto que no pasa de `$max` caracteres; si lo pasa, la fila falla y devuelve null. */
    public static function limit(ImportRow $row, string $key, ?string $value, int $max = 255): ?string
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $row->fail($key, 'too_long', "El texto supera los {$max} caracteres.");

            return null;
        }

        return $value;
    }

    /** Fecha de emisión de una factura: avisa (sin bloquear) si es futura o anterior al año 2000. */
    public static function issuedDate(ImportRow $row, DateTimeImmutable $issued): void
    {
        if ($issued > new DateTimeImmutable('+1 day')) {
            $row->warn('issued_on', 'future_date', 'La fecha es posterior a hoy.');
        } elseif ($issued < new DateTimeImmutable('2000-01-01')) {
            $row->warn('issued_on', 'old_date', 'La fecha es anterior al año 2000.');
        }
    }
}
