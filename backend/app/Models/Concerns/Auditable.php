<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Services\AuditLogger;

/**
 * Registra en audit_logs la creación y modificación del modelo.
 * Las contraseñas nunca se guardan: solo se anota que cambiaron.
 */
trait Auditable
{
    /** Atributos que no generan registro de auditoría por sí solos. */
    private static array $auditIgnored = ['updated_at', 'created_at', 'remember_token', 'last_login_at'];

    public static function bootAuditable(): void
    {
        static::created(fn ($model) => $model->writeAudit('created', null, $model->auditValues($model->getAttributes())));

        static::updated(function ($model) {
            $new = $model->auditValues($model->getChanges());

            if ($new === []) {
                return;
            }

            $old = array_intersect_key($model->auditValues($model->getRawOriginal()), $new);
            $model->writeAudit('updated', $old, $new);
        });
    }

    /** Prefijo del evento, p. ej. "user" → user.created. */
    abstract public static function auditPrefix(): string;

    /** Compañía a la que pertenece el registro, si aplica. */
    public function auditCompanyId(): ?int
    {
        return null;
    }

    private function auditValues(array $attributes): array
    {
        $values = array_diff_key($attributes, array_flip(self::$auditIgnored));

        if (array_key_exists('password', $values)) {
            $values['password'] = '[cambiada]';
        }

        return $values;
    }

    private function writeAudit(string $action, ?array $old, array $new): void
    {
        app(AuditLogger::class)->record(
            static::auditPrefix().'.'.$action,
            user: auth()->user(),
            auditable: $this,
            old: $old,
            new: $new,
            companyId: $this->auditCompanyId(),
        );
    }
}
