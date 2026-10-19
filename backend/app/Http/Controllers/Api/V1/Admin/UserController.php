<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;

/** Los usuarios no se eliminan: se desactivan con is_active. */
class UserController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        return AdminUserResource::collection(
            User::with('role', 'companies', 'branches')->orderBy('username')->get()
        );
    }

    public function roles(): JsonResponse
    {
        return response()->json(['data' => Role::orderBy('id')->get(['id', 'slug', 'name'])]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = DB::transaction(function () use ($request) {
            $user = User::create($request->safe()->except(['company_ids', 'branch_ids']));
            $this->syncScope($user, $request);

            return $user;
        });

        return (new AdminUserResource($this->loaded($user)))->response()->setStatusCode(201);
    }

    public function show(User $user): AdminUserResource
    {
        return new AdminUserResource($this->loaded($user));
    }

    public function update(UserRequest $request, User $user): AdminUserResource
    {
        DB::transaction(function () use ($request, $user) {
            $data = $request->safe()->except(['company_ids', 'branch_ids']);
            if (empty($data['password'])) {
                unset($data['password']);
            }
            $user->update($data);
            $this->syncScope($user, $request);
        });

        return new AdminUserResource($this->loaded($user));
    }

    private function syncScope(User $user, UserRequest $request): void
    {
        // Se usan las tablas pivote sin pasar por los global scopes del usuario autenticado.
        $user->companies()->withoutGlobalScopes()->sync($request->validated('company_ids'));
        $user->branches()->withoutGlobalScopes()->sync($request->validated('branch_ids'));
    }

    private function loaded(User $user): User
    {
        return $user->load('role', 'companies', 'branches');
    }
}
