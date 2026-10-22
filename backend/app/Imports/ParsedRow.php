<?php

declare(strict_types=1);

namespace App\Imports;

/** Fila validada y normalizada, lista para agruparse por documento. */
final class ParsedRow
{
    /**
     * @param  string  $groupKey  identifica el documento al que pertenece la fila
     * @param  array<string, string|null>  $groupFields  datos del documento que deben coincidir en todas sus líneas (clave de columna => valor)
     * @param  array<string, mixed>  $line  datos propios de la línea
     */
    public function __construct(
        public readonly int $rowNumber,
        public readonly string $groupKey,
        public readonly array $groupFields,
        public readonly array $line,
    ) {}
}
