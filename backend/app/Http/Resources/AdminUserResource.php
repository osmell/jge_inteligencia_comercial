<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin User */
class AdminUserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'name' => $this->name,
            'email' => $this->email,
            'is_active' => $this->is_active,
            'last_login_at' => $this->last_login_at,
            'role' => ['id' => $this->role->id, 'slug' => $this->role->slug, 'name' => $this->role->name],
            'company_ids' => $this->companies->pluck('id')->values(),
            'branch_ids' => $this->branches->pluck('id')->values(),
        ];
    }
}
