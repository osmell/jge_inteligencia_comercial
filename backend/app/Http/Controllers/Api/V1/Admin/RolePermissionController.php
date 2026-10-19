<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Permission;
use App\Models\Role;
use App\Services\AuditLogger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Matriz de permisos por rol. Un permiso corresponde a una opción del menú.
 * - El rol Administrador siempre tiene todos los permisos y no se puede modificar.
 * - «roles.manage» solo lo tiene el Administrador: quien pudiera concederse permisos a sí mismo escalaría privilegios.
 */
class RolePermissionController extends Controller
{
    public const ADMIN_ONLY = ['roles.manage'];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => [
                'permissions' => Permission::get(['id', 'slug', 'name'])
                    ->sortBy(fn ($p) => array_search($p->slug, array_keys(RolePermissionSeeder::PERMISSIONS), true))
                    ->values()->map(fn ($p) => [
                        'slug' => $p->slug,
                        'name' => $p->name,
                        'admin_only' => in_array($p->slug, self::ADMIN_ONLY, true),
                    ]),
                'roles' => Role::with('permissions')->orderBy('id')->get()->map(fn (Role $r) => [
                    'id' => $r->id,
                    'slug' => $r->slug,
                    'name' => $r->name,
                    'locked' => $r->slug === Role::ADMINISTRADOR,
                    'permissions' => $r->permissions->pluck('slug')->values(),
                ]),
            ],
        ]);
    }

    public function update(Request $request, Role $role, AuditLogger $audit): JsonResponse
    {
        abort_if($role->slug === Role::ADMINISTRADOR, 422, 'El rol Administrador siempre tiene todos los permisos.');

        $data = $request->validate([
            'permissions' => ['present', 'array'],
            'permissions.*' => ['string', 'distinct', Rule::exists('permissions', 'slug')],
        ]);

        $forbidden = array_intersect($data['permissions'], self::ADMIN_ONLY);
        if ($forbidden) {
            throw ValidationException::withMessages(['permissions' => 'El permiso «Roles y permisos» solo puede tenerlo el Administrador.']);
        }

        $old = $role->permissions()->pluck('slug')->sort()->values()->all();
        $role->permissions()->sync(Permission::whereIn('slug', $data['permissions'])->pluck('id'));
        $new = collect($data['permissions'])->sort()->values()->all();

        if ($old !== $new) {
            $audit->record('role.permissions_changed', $request->user(), auditable: $role, old: ['permissions' => $old], new: ['permissions' => $new]);
        }

        return $this->index();
    }
}
