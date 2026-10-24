<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;

/**
 * «Época de sesión»: cada sesión recuerda la época del usuario con la que se abrió, y se acepta solo mientras coincida con la actual.
 * Subir la época (al cerrar sesión, cambiar o restablecer la clave o desactivar la cuenta) invalida TODAS las sesiones anteriores de la
 * persona, incluida una que reviva porque una petición en curso volvió a guardarla después de cerrarla (ver docs/28, R3).
 */
class SessionEpoch
{
    public const KEY = 'session_epoch';

    public function __construct(private readonly SessionManager $sessions) {}

    public function bump(User $user): void
    {
        $user->increment('session_epoch');
    }

    /** La sesión actual queda ligada a la época vigente del usuario (al iniciar sesión, o al conservarla tras cambiar la clave). */
    public function adopt(User $user): void
    {
        $session = $this->sessions->driver();

        if ($session->isStarted()) {     // una petición sin sesión (token) no tiene nada que ligar
            $session->put(self::KEY, (int) $user->session_epoch);
        }
    }

    /** Una sesión sin época (abierta antes de existir este control) vale como época 0: deja de servir en cuanto la persona la sube. */
    public function isCurrent(Request $request, User $user): bool
    {
        return (int) $request->session()->get(self::KEY, 0) === (int) $user->session_epoch;
    }
}
