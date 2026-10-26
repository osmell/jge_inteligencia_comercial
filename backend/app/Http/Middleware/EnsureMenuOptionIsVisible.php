<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Menu\MenuCatalog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cumple lo que la administración decidió en «Menú por usuario»: si se ocultaron TODAS las opciones que usan una ruta, el servidor la
 * rechaza con 403 y el código `menu_option_hidden` (ocultar solo en la interfaz no sería seguridad). Una ruta que otra opción visible
 * también usa sigue disponible. Los administradores nunca tienen opciones ocultas.
 */
class EnsureMenuOptionIsVisible
{
    public const CODE = 'menu_option_hidden';

    public function __construct(private readonly MenuCatalog $catalog) {}

    public function handle(Request $request, Closure $next): Response
    {
        $hidden = $request->user()?->hiddenMenu() ?? [];

        if ($hidden !== []) {
            $owners = $this->catalog->owners(preg_replace('#^api/v1/#', '', $request->path()) ?? '');

            if ($owners !== [] && array_diff($owners, $hidden) === []) {
                return response()->json(['message' => 'Esta opción no está disponible para tu usuario.', 'code' => self::CODE], 403);
            }
        }

        return $next($request);
    }
}
