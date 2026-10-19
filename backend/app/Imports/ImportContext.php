<?php

namespace App\Imports;

use App\Models\ImportBatch;
use Closure;
use Generator;

/** Lo que recibe el tipo al finalizar: grupos consolidados, errores y resumen que puede completar. */
final class ImportContext
{
    public int $documentsNew = 0;

    public int $documentsDuplicate = 0;

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @var array<string, true> claves de documentos que ya existen con contenido idéntico (no se vuelven a escribir) */
    public array $existingKeys = [];

    /**
     * @param  array<string, ImportGroup>  $groups
     * @param  (Closure(): Generator<int, ParsedRow>)|null  $rowStream  vuelve a leer el archivo fila por fila (solo al importar)
     */
    public function __construct(
        public readonly ImportBatch $batch,
        public readonly array $groups,
        public readonly ErrorCollector $errors,
        private readonly ?Closure $rowStream = null,
    ) {}

    /** @return Generator<int, ParsedRow> */
    public function rows(): Generator
    {
        yield from ($this->rowStream ?? throw new \LogicException('Este contexto no puede releer el archivo.'))();
    }
}
