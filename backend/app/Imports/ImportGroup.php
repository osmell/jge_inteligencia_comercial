<?php

namespace App\Imports;

/** Un documento (factura) formado por una o más líneas del archivo. */
final class ImportGroup
{
    /** @var list<int> */
    public array $rowNumbers = [];

    /** @var list<string> */
    public array $lineHashes = [];

    /** @var list<ParsedRow> solo si se pidió conservar las líneas (importación) */
    public array $rows = [];

    /** @param  array<string, string|null>  $fields */
    public function __construct(public readonly string $key, public readonly array $fields, public readonly int $firstRow) {}

    public function linesCount(): int
    {
        return count($this->lineHashes);
    }

    /** Huella del contenido, independiente del orden de las líneas. */
    public function contentHash(): string
    {
        $hashes = $this->lineHashes;
        sort($hashes);

        return hash('sha256', json_encode([$this->fields, $hashes], JSON_THROW_ON_ERROR));
    }
}
