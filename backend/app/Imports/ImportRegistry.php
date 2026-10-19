<?php

namespace App\Imports;

use App\Imports\Contracts\ImportType;
use InvalidArgumentException;

final class ImportRegistry
{
    /** @return list<string> */
    public function keys(): array
    {
        return array_keys(config('imports.types', []));
    }

    public function has(string $key): bool
    {
        return isset(config('imports.types', [])[$key]);
    }

    public function make(string $key): ImportType
    {
        $class = config('imports.types', [])[$key] ?? throw new InvalidArgumentException("Tipo de importación desconocido: {$key}");

        return app($class);
    }
}
