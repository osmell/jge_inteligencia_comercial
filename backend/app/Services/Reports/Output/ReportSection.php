<?php

declare(strict_types=1);

namespace App\Services\Reports\Output;

/**
 * Una tabla del documento: sus columnas y sus filas (cada fila tiene una celda por columna). `anchors` son las primeras columnas que
 * identifican la fila: en el PDF, si la tabla no cabe a lo ancho, se parte en bloques de columnas y cada bloque repite esas.
 */
final readonly class ReportSection
{
    /**
     * @param  list<ReportColumn>  $columns
     * @param  list<list<mixed>>  $rows
     */
    public function __construct(public string $title, public array $columns, public array $rows, public ?string $note = null, public int $anchors = 2) {}
}
