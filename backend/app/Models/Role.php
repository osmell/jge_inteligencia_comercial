<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property Collection<int, Permission> $permissions
 */
#[Fillable(['slug', 'name'])]
class Role extends Model
{
    public const ADMINISTRADOR = 'administrador';

    public const GERENTE_GENERAL = 'gerente_general';

    public const SUPERVISOR_VENTAS = 'supervisor_ventas';

    public const VENDEDOR = 'vendedor';

    /** @return BelongsToMany<Permission, $this> */
    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
