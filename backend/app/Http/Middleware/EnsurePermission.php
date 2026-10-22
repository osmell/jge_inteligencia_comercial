<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /** Uso: ->middleware('permission:users.manage') — exige todos los permisos indicados. */
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        abort_unless(
            $user && $user->is_active && collect($permissions)->every(fn (string $p) => $user->hasPermission($p)),
            403,
            'No tienes permiso para realizar esta acción.',
        );

        return $next($request);
    }
}
