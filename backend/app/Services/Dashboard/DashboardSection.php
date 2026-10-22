<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;

/**
 * Una sección del dashboard. Para agregar una, se crea una clase que extienda esta y se añade a la lista de
 * `AppServiceProvider` (el orden de la lista es el orden en pantalla); `DashboardService` no se toca.
 */
abstract class DashboardSection
{
    /** Identificador de la sección en la API. */
    abstract public function key(): string;

    /** Permiso que exige: el mismo de su pantalla de origen. */
    abstract public function permission(): string;

    /** @return array<string, mixed> */
    abstract public function build(DashboardContext $context): array;

    /** Reglas extra además del permiso (por ejemplo, que tenga sentido para el perfil). */
    public function availableFor(User $user, string $profile): bool
    {
        return true;
    }
}
