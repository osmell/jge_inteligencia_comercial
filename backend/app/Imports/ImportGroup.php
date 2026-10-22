<?php

declare(strict_types=1);

namespace App\Imports;

/** Un documento (factura) formado por una o más líneas del archivo. Guarda solo huellas, no las líneas. */
final class ImportGroup
{
    /** @var list<string> huellas binarias (16 bytes) de cada línea, para detectar repeticiones y comparar contenido */
    public array $lineHashes = [];

    /** @param  array<string, string|null>  $fields */
    public function __construct(public readonly string $key, public readonly array $fields, public readonly int $firstRow) {}

    public function linesCount(): int
    {
        return count($this->lineHashes);
    }

    /** Huella del contenido del documento, independiente del orden de sus líneas. */
    public function contentHash(): string
    {
        $hashes = $this->lineHashes;
        sort($hashes);

        return hash('sha256', json_encode($this->fields, JSON_THROW_ON_ERROR).implode('', $hashes));
    }
}
