<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;

/**
 * Entrega avisos internos. Reglas, todas en el servidor: el aviso solo llega a un usuario activo que tenga el permiso del tipo
 * (nunca se avisa de algo que la persona no podría ver); respeta las preferencias de la persona (tipo apagado o avisos
 * pausados); y se identifica por una clave de deduplicación, así que repetir el envío (reintento, recálculo) no crea otro aviso
 * ni resucita uno descartado.
 */
class Notifier
{
    /** @return array<string, array<string, mixed>> */
    public function types(): array
    {
        return (array) config('notifications.types');
    }

    /** Tipos que la persona puede recibir, con si los tiene activados. @return list<array<string, mixed>> */
    public function typesFor(User $user): array
    {
        $prefs = DB::table('notification_preferences')->where('user_id', $user->id)->pluck('enabled', 'type');
        $out = [];
        foreach ($this->types() as $key => $t) {
            if ($t['permission'] !== null && ! $user->hasPermission($t['permission'])) {
                continue;
            }
            $out[] = ['type' => $key, 'label' => $t['label'], 'enabled' => isset($prefs[$key]) ? (bool) $prefs[$key] : (bool) $t['default']];
        }

        return $out;
    }

    public function isPaused(User $user): bool
    {
        return (bool) DB::table('notification_settings')->where('user_id', $user->id)->value('paused');
    }

    /** Si el aviso de este tipo le corresponde y lo quiere recibir. */
    public function wants(User $user, string $type): bool
    {
        $definition = $this->types()[$type] ?? null;
        if ($definition === null || ! $user->is_active) {
            return false;
        }
        if ($definition['permission'] !== null && ! $user->hasPermission($definition['permission'])) {
            return false;
        }
        if ($this->isPaused($user)) {
            return false;
        }
        $pref = DB::table('notification_preferences')->where(['user_id' => $user->id, 'type' => $type])->value('enabled');

        return $pref === null ? (bool) $definition['default'] : (bool) $pref;
    }

    /**
     * @return UserNotification|null el aviso creado o ya existente; null si no corresponde entregarlo
     */
    public function send(User $user, string $type, string $title, ?string $body, ?string $url, string $dedupeKey, string $severity = 'info', ?int $companyId = null): ?UserNotification
    {
        if (! $this->wants($user, $type)) {
            return null;
        }
        $existing = UserNotification::query()->where(['user_id' => $user->id, 'dedupe_key' => $dedupeKey])->first();
        if ($existing !== null) {
            return $existing;
        }

        return UserNotification::create([
            'user_id' => $user->id, 'company_id' => $companyId, 'type' => $type, 'severity' => $severity, 'title' => mb_substr($title, 0, 255), 'body' => $body === null ? null : mb_substr($body, 0, 1000),
            'url' => $url, 'dedupe_key' => $dedupeKey, 'status' => UserNotification::NEW, 'created_at' => now(),
        ]);
    }

    /** Borra los avisos vistos o descartados más antiguos que el tiempo de conservación. */
    public function prune(): int
    {
        return UserNotification::query()->where('status', '!=', UserNotification::NEW)->where('created_at', '<', now()->subDays((int) config('notifications.retention_days')))->delete();
    }
}
