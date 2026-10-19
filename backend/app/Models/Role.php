<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['slug', 'name'])]
class Role extends Model
{
    public const ADMINISTRADOR = 'administrador';
    public const GERENTE_GENERAL = 'gerente_general';
    public const SUPERVISOR_VENTAS = 'supervisor_ventas';
    public const VENDEDOR = 'vendedor';

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(Permission::class);
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }
}
