<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Ámbito de datos de un usuario: qué compañías y sucursales puede ver.
 *
 * - Administrador: sin restricción.
 * - Resto de roles: solo las compañías asignadas en company_user. Si tiene sucursales
 *   asignadas dentro de una compañía, queda limitado a ellas; si no, ve todas las de esa compañía.
 * - Un usuario sin asignaciones no ve nada (falla cerrado).
 *
 * Consulta las tablas pivote directamente para no disparar los global scopes que usan esta clase.
 */
class DataScope
{
    /** @var array<int, array{global: bool, companies: list<int>, restricted: array<int, list<int>>}> */
    private array $cache = [];

    /** @return array{global: bool, companies: list<int>, restricted: array<int, list<int>>} */
    public function for(User $user): array
    {
        return $this->cache[$user->id] ??= $this->resolve($user);
    }

    /** Compañías asignadas al usuario; null si no tiene restricción (Administrador). @return list<int>|null */
    public function companyIds(User $user): ?array
    {
        $scope = $this->for($user);

        return $scope['global'] ? null : $scope['companies'];
    }

    /**
     * Compañías sobre las que el usuario tiene acceso completo (sin restricción de sucursales).
     * Se usa para acciones de administración: quien está limitado a ciertas sucursales no administra la compañía.
     *
     * @return list<int>|null null si no tiene restricción
     */
    public function fullCompanyIds(User $user): ?array
    {
        $scope = $this->for($user);

        if ($scope['global']) {
            return null;
        }

        return array_values(array_diff($scope['companies'], array_keys($scope['restricted'])));
    }

    public function forget(): void
    {
        $this->cache = [];
    }

    private function resolve(User $user): array
    {
        if ($user->seesAllOrganizations()) {
            return ['global' => true, 'companies' => [], 'restricted' => []];
        }

        $companies = DB::table('company_user')->where('user_id', $user->id)->pluck('company_id')->map(fn ($id) => (int) $id)->all();

        $restricted = [];
        $rows = DB::table('branch_user')
            ->join('branches', 'branches.id', '=', 'branch_user.branch_id')
            ->where('branch_user.user_id', $user->id)
            ->whereIn('branches.company_id', $companies)
            ->get(['branches.id', 'branches.company_id']);

        foreach ($rows as $row) {
            $restricted[(int) $row->company_id][] = (int) $row->id;
        }

        return ['global' => false, 'companies' => $companies, 'restricted' => $restricted];
    }
}
