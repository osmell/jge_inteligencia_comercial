<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\SessionEpoch;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Rechaza una sesión abierta con una época anterior a la vigente del usuario (cerró sesión, cambió su clave, fue restablecida o desactivada). */
class EnsureSessionIsCurrent
{
    public const CODE = 'session_revoked';

    public function __construct(private readonly SessionEpoch $epoch) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user !== null && $request->hasSession() && ! $this->epoch->isCurrent($request, $user)) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();

            return response()->json(['message' => 'Tu sesión ya no es válida. Inicia sesión de nuevo.', 'code' => self::CODE], 401);
        }

        return $next($request);
    }
}
