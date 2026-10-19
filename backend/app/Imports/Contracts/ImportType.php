<?php

namespace App\Imports\Contracts;

use App\Imports\ColumnDefinition;
use App\Imports\ImportContext;
use App\Imports\ImportRow;
use App\Imports\ParsedRow;

/**
 * Un tipo de plantilla (ventas, clientes, productos…). El marco se encarga del archivo, los encabezados,
 * la agrupación por documento, los errores y los estados; el tipo define sus columnas y reglas.
 */
interface ImportType
{
    public function key(): string;

    /** @return list<ColumnDefinition> */
    public function columns(): array;

    /** Lee y valida una fila. Devuelve null (o registra errores en la fila) si no es válida. */
    public function parseRow(ImportRow $row): ?ParsedRow;

    /** Se ejecuta tras leer todo el archivo, con los grupos ya consolidados (controles contra la base de datos, resumen). */
    public function finalize(ImportContext $context): void;
}
