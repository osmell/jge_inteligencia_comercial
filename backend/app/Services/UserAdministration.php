<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Alta y edición de usuarios con su alcance (compañías y sucursales); los cambios de alcance quedan en Auditoría. */
class UserAdministration
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $companyIds
     * @param  list<int>  $branchIds
     */
    public function create(User $actor, array $attributes, array $companyIds, array $branchIds): User
    {
        return DB::transaction(function () use ($actor, $attributes, $companyIds, $branchIds) {
            // La clave que fija administración es temporal: la persona debe cambiarla en su primer ingreso.
            $user = User::create($attributes + ['must_change_password' => true]);
            $this->syncScope($actor, $user, $companyIds, $branchIds);

            return $user;
        });
    }

    /**
     * La contraseña vacía conserva la actual.
     *
     * @param  array<string, mixed>  $attributes
     * @param  list<int>  $companyIds
     * @param  list<int>  $branchIds
     */
    public function update(User $actor, User $user, array $attributes, array $companyIds, array $branchIds): User
    {
        DB::transaction(function () use ($actor, $user, $attributes, $companyIds, $branchIds) {
            if (empty($attributes['password'])) {
                unset($attributes['password']);
            } elseif ($actor->is($user)) {
                // Quien se pone su propia clave no tiene que cambiarla después.
                $attributes += ['must_change_password' => false, 'password_changed_at' => now()];
            } else {
                // Restablecimiento por administración: la clave nueva es temporal y la persona debe cambiarla al entrar.
                $attributes += ['must_change_password' => true, 'password_changed_at' => null];
                $this->audit->record('user.password_reset', $actor, auditable: $user, companyId: null);
            }
            $user->update($attributes);
            $this->syncScope($actor, $user, $companyIds, $branchIds);
        });

        return $user;
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $branchIds
     */
    private function syncScope(User $actor, User $user, array $companyIds, array $branchIds): void
    {
        // Se usan las tablas pivote sin pasar por los global scopes del usuario autenticado.
        $old = [
            'company_ids' => $user->companies()->withoutGlobalScopes()->pluck('companies.id')->sort()->values()->all(),
            'branch_ids' => $user->branches()->withoutGlobalScopes()->pluck('branches.id')->sort()->values()->all(),
        ];
        $user->companies()->withoutGlobalScopes()->sync($companyIds);
        $user->branches()->withoutGlobalScopes()->sync($branchIds);
        $new = [
            'company_ids' => collect($companyIds)->sort()->values()->all(),
            'branch_ids' => collect($branchIds)->sort()->values()->all(),
        ];

        if ($old !== $new) {
            $this->audit->record('user.scope_changed', $actor, auditable: $user, old: $old, new: $new);
        }
    }
}
