<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Una cuenta con clave temporal (alta nueva o restablecida por administración) solo puede ver su perfil, cambiar la clave y salir:
 * el resto de la API responde 403 con el código `password_change_required` hasta que la cambie. La interfaz lo usa para llevarla a la
 * pantalla de cambio; la regla se aplica aquí, en el servidor, no en la interfaz.
 */
class EnsurePasswordIsCurrent
{
    public const CODE = 'password_change_required';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->must_change_password === true) {
            return response()->json(['message' => 'Debes cambiar tu clave antes de continuar.', 'code' => self::CODE], 403);
        }

        return $next($request);
    }
}
