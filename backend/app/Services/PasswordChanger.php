<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * Cambio de clave por la propia persona: pide la clave actual (aunque la sesión esté abierta), rechaza repetirla o usar el nombre de
 * usuario, limita los intentos (con una sesión robada no se puede adivinar la clave actual) y deja el cambio en Auditoría sin guardar
 * ninguna clave. Al cambiarla termina la obligación de cambio del primer ingreso o del restablecimiento.
 */
class PasswordChanger
{
    private const MAX_ATTEMPTS = 5;

    public function __construct(private readonly AuditLogger $audit, private readonly SessionEpoch $epoch) {}

    public function change(User $user, string $current, string $new): void
    {
        $key = 'password-change|'.$user->id;
        if (RateLimiter::tooManyAttempts($key, self::MAX_ATTEMPTS)) {
            throw ValidationException::withMessages([
                'current_password' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)]),
            ])->status(429);
        }
        if (! Hash::check($current, $user->password)) {
            RateLimiter::hit($key);

            throw ValidationException::withMessages(['current_password' => 'La clave actual no es correcta.']);
        }
        if (Hash::check($new, $user->password)) {
            throw ValidationException::withMessages(['password' => 'La nueva clave debe ser distinta de la actual.']);
        }
        if (mb_strtolower($new) === mb_strtolower($user->username)) {
            throw ValidationException::withMessages(['password' => 'La clave no puede ser igual al nombre de usuario.']);
        }

        DB::transaction(function () use ($user, $new) {
            $user->forceFill(['password' => $new, 'must_change_password' => false, 'password_changed_at' => now()])->save();
            $this->epoch->bump($user);   // cualquier otra sesión abierta con la clave anterior deja de servir
            $this->audit->record('auth.password_changed', $user);
        });
        RateLimiter::clear($key);
    }
}
