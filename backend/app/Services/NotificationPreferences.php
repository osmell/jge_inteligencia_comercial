<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Preferencias de avisos de una persona: pausa general y, por tipo, campana y correo. */
class NotificationPreferences
{
    public function __construct(private readonly Notifier $notifier) {}

    /**
     * @param  array{paused?: bool|null, types?: array<string, bool>|null, email?: array<string, bool>|null}  $data
     */
    public function update(User $user, array $data): void
    {
        $allowed = array_column($this->notifier->typesFor($user), 'type');

        DB::transaction(function () use ($user, $allowed, $data) {
            if (array_key_exists('paused', $data) && $data['paused'] !== null) {
                DB::table('notification_settings')->updateOrInsert(['user_id' => $user->id], ['paused' => (bool) $data['paused']]);
            }
            foreach (($data['types'] ?? []) as $type => $enabled) {
                if (in_array($type, $allowed, true)) {
                    DB::table('notification_preferences')->updateOrInsert(['user_id' => $user->id, 'type' => $type], ['enabled' => (bool) $enabled]);
                }
            }
            foreach (($data['email'] ?? []) as $type => $email) {
                if (in_array($type, $allowed, true)) {
                    $enabled = DB::table('notification_preferences')->where(['user_id' => $user->id, 'type' => $type])->value('enabled');
                    DB::table('notification_preferences')->updateOrInsert(['user_id' => $user->id, 'type' => $type], ['email' => (bool) $email, 'enabled' => $enabled ?? (bool) (config("notifications.types.{$type}.default") ?? true)]);
                }
            }
        });
    }
}
