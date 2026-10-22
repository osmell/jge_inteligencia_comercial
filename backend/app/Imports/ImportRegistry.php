<?php

declare(strict_types=1);

namespace App\Imports;

use App\Imports\Contracts\ImportType;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class ImportRegistry
{
    public function __construct(private readonly Container $container) {}

    /** @return list<string> */
    public function keys(): array
    {
        return array_map('strval', array_keys(config('imports.types', [])));
    }

    public function has(string $key): bool
    {
        return isset(config('imports.types', [])[$key]);
    }

    public function make(string $key): ImportType
    {
        $class = config('imports.types', [])[$key] ?? throw new InvalidArgumentException("Tipo de importación desconocido: {$key}");

        return $this->container->make($class);
    }
}
