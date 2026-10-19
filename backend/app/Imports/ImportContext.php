<?php

namespace App\Imports;

use App\Models\ImportBatch;

/** Lo que recibe el tipo al finalizar: grupos consolidados, errores y resumen que puede completar. */
final class ImportContext
{
    public int $documentsNew = 0;

    public int $documentsDuplicate = 0;

    /** @var array<string, mixed> */
    public array $summary = [];

    /** @param  array<string, ImportGroup>  $groups */
    public function __construct(
        public readonly ImportBatch $batch,
        public readonly array $groups,
        public readonly ErrorCollector $errors,
    ) {}
}
