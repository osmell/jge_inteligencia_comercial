<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $username
 * @property string $name
 * @property string|null $email
 * @property string $password
 * @property int $role_id
 * @property bool $is_active
 * @property Carbon|null $last_login_at
 * @property Role $role
 * @property Collection<int, Company> $companies
 * @property Collection<int, Branch> $branches
 */
#[Fillable(['username', 'name', 'email', 'password', 'role_id', 'is_active'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory;

    public static function auditPrefix(): string
    {
        return 'user';
    }

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    /** Los nombres de usuario se guardan en minúsculas para que sean únicos sin distinguir mayúsculas. */
    protected function username(): Attribute
    {
        return Attribute::set(fn (string $value) => mb_strtolower(trim($value)));
    }

    /** @return BelongsTo<Role, $this> */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    /** @return BelongsToMany<Company, $this> */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class);
    }

    /** @return BelongsToMany<Branch, $this> */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class);
    }

    public function hasRole(string ...$slugs): bool
    {
        return in_array($this->role->slug, $slugs, true);
    }

    /** Administrador ve todas las compañías y sucursales; el resto, solo su ámbito asignado. */
    public function seesAllOrganizations(): bool
    {
        return $this->hasRole(Role::ADMINISTRADOR);
    }

    public function hasPermission(string $slug): bool
    {
        return $this->role->permissions->contains('slug', $slug);
    }
}
