<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cabeceras de seguridad en todas las respuestas del servidor (API y archivos). No pisa las que una respuesta ya trae (por ejemplo la
 * política más estricta de los archivos adjuntos). La política de contenido de la interfaz web pertenece al servidor que la publica
 * (ver docs/28_SEGURIDAD.md).
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        header_remove('X-Powered-By');         // no anuncia la versión de PHP
        $response->headers->remove('X-Powered-By');

        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=()',
            'Cross-Origin-Resource-Policy' => 'same-site',
        ];
        // La API solo devuelve datos: ningún recurso activo puede cargarse desde sus respuestas. La documentación (/docs) trae su propia interfaz.
        if (! $request->is('docs/*')) {
            $headers['Content-Security-Policy'] = "default-src 'none'; frame-ancestors 'none'; base-uri 'none'";
        }
        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }
}
