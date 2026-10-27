<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\MenuAccessRequest;
use App\Models\User;
use App\Services\Menu\MenuCatalog;
use Illuminate\Http\JsonResponse;

/**
 * Qué opciones del menú ve cada persona, incluidos los administradores (que pueden ocultarse las suyas; esta pantalla no se puede ocultar). Solo QUITA (la persona sigue necesitando el permiso de su rol) y el
 * servidor lo hace cumplir (`EnsureMenuOptionIsVisible`). Solo administradores; los cambios quedan en Auditoría (`user.updated`).
 */
class MenuAccessController extends Controller
{
    /**
     * @response array{data: array{options: list<array{key: string, group: string, label: string, permission: string}>, users: list<array{id: int, username: string, name: string, is_active: bool, role: array{slug: string, name: string}, available: list<string>, hidden: list<string>}>}}
     */
    public function index(): JsonResponse
    {
        $users = User::query()->with('role.permissions')->orderBy('name')->get();

        return response()->json(['data' => [
            'options' => collect(MenuCatalog::OPTIONS)->map(fn (array $o, string $key) => ['key' => $key] + $o)->values(),
            'users' => $users->map(fn (User $u) => $this->row($u))->values(),
        ]]);
    }

    /**
     * @response array{data: array{id: int, username: string, name: string, is_active: bool, role: array{slug: string, name: string}, available: list<string>, hidden: list<string>}}
     */
    public function update(MenuAccessRequest $request, User $user): JsonResponse
    {
        $hidden = $request->hidden();
        $user->hidden_menu = $hidden === [] ? null : $hidden;
        $user->save();

        return response()->json(['data' => $this->row($user->load('role.permissions'))]);
    }

    /** @return array<string, mixed> */
    private function row(User $user): array
    {
        $permissions = $user->role->permissions->pluck('slug');

        return [
            'id' => $user->id, 'username' => $user->username, 'name' => $user->name, 'is_active' => $user->is_active,
            'role' => ['slug' => $user->role->slug, 'name' => $user->role->name],
            // Las opciones que su rol ya le permite: solo sobre esas tiene sentido ocultar algo.
            'available' => array_keys(array_filter(MenuCatalog::OPTIONS, fn (array $o) => $permissions->contains($o['permission']))),
            'hidden' => $user->hiddenMenu(),
        ];
    }
}
