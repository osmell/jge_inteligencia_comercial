<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Solo el rol Administrador: lo que no debe poder concederse a otro rol desde «Roles y permisos» (como decidir qué menú ve cada persona). */
class EnsureAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_active && $request->user()->isAdministrator(), 403, 'Solo un administrador puede hacerlo.');

        return $next($request);
    }
}
