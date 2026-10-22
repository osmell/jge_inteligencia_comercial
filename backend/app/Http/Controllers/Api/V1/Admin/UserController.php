<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\UserRequest;
use App\Http\Resources\AdminUserResource;
use App\Models\Role;
use App\Models\User;
use App\Services\UserAccess;
use App\Services\UserAdministration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/** Los usuarios no se eliminan: se desactivan con is_active. */
class UserController extends Controller
{
    public function __construct(private readonly UserAccess $access, private readonly UserAdministration $users) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $users = $this->access->manageable($request->user(), User::query())
            ->with('role', 'companies', 'branches')->orderBy('username')->get();

        return AdminUserResource::collection($users);
    }

    /** Roles que el usuario autenticado puede asignar. */
    public function roles(Request $request): JsonResponse
    {
        $roles = Role::orderBy('id')->get(['id', 'slug', 'name'])
            ->filter(fn (Role $r) => $this->access->canAssignRole($request->user(), $r))->values();

        return response()->json(['data' => $roles]);
    }

    public function store(UserRequest $request): JsonResponse
    {
        $user = $this->users->create($request->user(), $request->safe()->except(['company_ids', 'branch_ids']), $request->validated('company_ids'), $request->validated('branch_ids'));

        return (new AdminUserResource($this->loaded($user)))->response()->setStatusCode(201);
    }

    public function show(Request $request, User $user): AdminUserResource
    {
        abort_unless($this->access->canManage($request->user(), $user), 404);

        return new AdminUserResource($this->loaded($user));
    }

    public function update(UserRequest $request, User $user): AdminUserResource
    {
        abort_unless($this->access->canManage($request->user(), $user), 404);

        $this->users->update($request->user(), $user, $request->safe()->except(['company_ids', 'branch_ids']), $request->validated('company_ids'), $request->validated('branch_ids'));

        return new AdminUserResource($this->loaded($user));
    }

    private function loaded(User $user): User
    {
        return $user->load('role', 'companies', 'branches');
    }
}
