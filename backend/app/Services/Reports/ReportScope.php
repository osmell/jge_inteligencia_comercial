<?php

declare(strict_types=1);

namespace App\Services\Reports;

use App\Models\User;
use App\Services\DataScope;

/**
 * Compañías sobre las que una persona puede ver reportes. Los reportes de evaluación miran por COMPAÑÍA, no por sucursal: quien
 * supervisa evalúa a todo el equipo de su compañía aunque sus sucursales asignadas sean pocas.
 */
class ReportScope
{
    public function __construct(private readonly DataScope $scope) {}

    /**
     * Compañías a consultar: las de la persona, o solo la pedida si está dentro de ellas.
     * Una compañía pedida fuera de su alcance deja la lista vacía (no devuelve nada, falla cerrado).
     *
     * @return list<int>|null null = todas (administración sin filtro)
     */
    public function companyIds(User $user, ?int $requested = null): ?array
    {
        $own = $this->scope->companyIds($user);

        if ($requested === null) {
            return $own;
        }

        return $own === null || in_array($requested, $own, true) ? [$requested] : [];
    }

    public function allows(User $user, int $companyId): bool
    {
        $own = $this->scope->companyIds($user);

        return $own === null || in_array($companyId, $own, true);
    }
}
