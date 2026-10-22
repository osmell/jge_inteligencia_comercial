<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class UserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'must_change_password' => (bool) $this->must_change_password,
            'role' => ['slug' => $this->role->slug, 'name' => $this->role->name],
            'permissions' => $this->role->permissions->pluck('slug')->values(),
            'companies' => $this->companies->map(fn ($c) => ['id' => $c->id, 'code' => $c->code, 'name' => $c->name])->values(),
            'branches' => $this->branches->map(fn ($b) => ['id' => $b->id, 'company_id' => $b->company_id, 'code' => $b->code, 'name' => $b->name])->values(),
        ];
    }
}
