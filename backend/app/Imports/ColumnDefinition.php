<?php

namespace App\Imports;

final class ColumnDefinition
{
    /**
     * @param  string  $key  clave interna
     * @param  list<string>  $aliases  encabezados aceptados; el primero es el nombre canónico
     */
    public function __construct(
        public readonly string $key,
        public readonly array $aliases,
        public readonly bool $required = true,
    ) {}

    public function label(): string
    {
        return $this->aliases[0];
    }
}
