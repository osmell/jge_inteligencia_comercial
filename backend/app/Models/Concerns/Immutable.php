<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use LogicException;

/**
 * Los registros históricos de ventas no se modifican ni se eliminan desde la aplicación.
 * Las correcciones deben hacerse mediante un proceso explícito con auditoría (no existe en esta fase).
 */
trait Immutable
{
    public static function bootImmutable(): void
    {
        static::updating(fn ($model) => throw new LogicException(class_basename($model).' es inmutable: no se puede modificar.'));
        static::deleting(fn ($model) => throw new LogicException(class_basename($model).' es inmutable: no se puede eliminar.'));
    }
}
