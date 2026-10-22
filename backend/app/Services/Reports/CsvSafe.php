<?php

declare(strict_types=1);

namespace App\Services\Reports;

/** Evita que Excel interprete como fórmula un texto que viene de los archivos importados. */
final class CsvSafe
{
    public static function value(?string $value): ?string
    {
        return $value !== null && preg_match('/^[=+\-@\t\r]/', $value) ? "'".$value : $value;
    }
}
