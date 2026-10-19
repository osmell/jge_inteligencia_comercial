<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Reglas de quién puede administrar a qué usuarios. Evita escalada de privilegios cuando
 * users.manage se concede a un rol distinto del Administrador:
 * - nunca puede ver, editar ni asignar el rol Administrador;
 * - solo administra usuarios cuyas compañías estén dentro de las compañías en las que tiene acceso completo;
 * - solo puede asignar esas compañías.
 */
class UserAccess
{
    public function __construct(private readonly DataScope $scope) {}

    /** @param  Builder<User>  $query
     * @return Builder<User> */
    public function manageable(User $actor, Builder $query): Builder
    {
        $full = $this->scope->fullCompanyIds($actor);

        if ($full === null) {
            return $query;
        }

        return $query
            ->whereDoesntHave('role', fn (Builder $q) => $q->where('slug', Role::ADMINISTRADOR))
            ->whereDoesntHave('companies', fn (Builder $q) => $q->withoutGlobalScopes()->whereNotIn('companies.id', $full));
    }

    public function canManage(User $actor, User $target): bool
    {
        return $this->manageable($actor, User::query())->whereKey($target->id)->exists();
    }

    /** @return list<int>|null null = sin restricción */
    public function assignableCompanyIds(User $actor): ?array
    {
        return $this->scope->fullCompanyIds($actor);
    }

    public function canAssignRole(User $actor, Role $role): bool
    {
        return $actor->seesAllOrganizations() || $role->slug !== Role::ADMINISTRADOR;
    }
}
