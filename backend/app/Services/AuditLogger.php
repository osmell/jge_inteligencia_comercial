<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

class AuditLogger
{
    public function __construct(private readonly Request $request) {}

    /**
     * @param  array<string, mixed>|null  $old
     * @param  array<string, mixed>|null  $new
     */
    public function record(
        string $event,
        ?User $user = null,
        ?string $username = null,
        ?Model $auditable = null,
        ?array $old = null,
        ?array $new = null,
        ?int $companyId = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => $user?->id,
            'username' => $username ?? $user?->username,
            'event' => $event,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'company_id' => $companyId,
            'ip_address' => $this->request->ip(),
            'user_agent' => $this->request->userAgent(),
            'old_values' => $old,
            'new_values' => $new,
        ]);
    }
}
