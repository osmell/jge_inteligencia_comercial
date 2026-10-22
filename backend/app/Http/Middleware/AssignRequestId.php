<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Da a cada petición un identificador (el que traiga en `X-Request-Id`, si es válido, o uno nuevo) y lo agrega a todos los registros
 * de esa petición y a la respuesta. Con él se une lo que vio la persona con lo que quedó en el registro del servidor.
 */
class AssignRequestId
{
    public function handle(Request $request, Closure $next): Response
    {
        $given = (string) $request->header('X-Request-Id', '');
        $id = preg_match('/^[A-Za-z0-9._-]{8,64}$/', $given) === 1 ? $given : (string) Str::uuid();
        $request->headers->set('X-Request-Id', $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $id);

        return $response;
    }
}
