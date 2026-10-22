<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property int $id
 * @property int|null $user_id
 * @property string|null $username
 * @property string $event
 */
#[Fillable(['user_id', 'username', 'event', 'auditable_type', 'auditable_id', 'company_id', 'ip_address', 'user_agent', 'old_values', 'new_values'])]
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    /** El registro de auditoría es de solo escritura: no se modifica ni se elimina. */
    protected static function booted(): void
    {
        static::updating(fn () => false);
        static::deleting(fn () => false);
    }

    protected function casts(): array
    {
        return ['old_values' => 'array', 'new_values' => 'array'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
