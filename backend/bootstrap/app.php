<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureAdministrator;
use App\Http\Middleware\EnsureMenuOptionIsVisible;
use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Autorización de los canales privados del tiempo real (sesión de la SPA).
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum', 'session.current', 'active', 'password.current']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
        // Detrás de un balanceador o proxy: sin esto todas las peticiones parecen venir de la misma dirección y los límites de intentos
        // (que usan la dirección del cliente) se aplicarían a todo el mundo a la vez. TRUSTED_PROXIES = «*» o una lista de direcciones.
        if (is_string(env('TRUSTED_PROXIES')) && env('TRUSTED_PROXIES') !== '') {
            $middleware->trustProxies(at: env('TRUSTED_PROXIES') === '*' ? '*' : array_map('trim', explode(',', (string) env('TRUSTED_PROXIES'))));
        }
        // Cabeceras de seguridad en toda respuesta y límite general de peticiones de la API (ver docs/28_SEGURIDAD.md).
        $middleware->append(SecurityHeaders::class);
        $middleware->throttleApi();
        // Identificador de petición en todos los registros y en la respuesta (X-Request-Id).
        $middleware->api(prepend: [AssignRequestId::class]);
        // El permiso se comprueba antes de resolver el modelo: sin permiso siempre 403, nunca 404.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsurePermission::class,
        );
        // «Solo administradores» se comprueba antes de resolver el modelo: un usuario que no lo es recibe 403, nunca 404 (no puede sondear identificadores).
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: EnsureAdministrator::class,
        );
        // La clave temporal se comprueba antes que el permiso y que la resolución de modelos: la respuesta es siempre `password_change_required`.
        $middleware->prependToPriorityList(
            before: EnsurePermission::class,
            prepend: EnsurePasswordIsCurrent::class,
        );
        // Las opciones del menú que la administración ocultó a una persona se rechazan antes que el permiso y que la resolución de modelos.
        $middleware->prependToPriorityList(
            before: EnsurePermission::class,
            prepend: EnsureMenuOptionIsVisible::class,
        );
        // Una sesión revocada se rechaza antes que todo lo demás (siempre 401 con `session_revoked`).
        $middleware->prependToPriorityList(
            before: EnsurePasswordIsCurrent::class,
            prepend: EnsureSessionIsCurrent::class,
        );
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'administrator' => EnsureAdministrator::class,
            'menu.visible' => EnsureMenuOptionIsVisible::class,
            'active' => EnsureUserIsActive::class,
            'password.current' => EnsurePasswordIsCurrent::class,
            'session.current' => EnsureSessionIsCurrent::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Quién estaba conectado cuando ocurrió el error (el identificador de petición ya viaja en el contexto compartido).
        $exceptions->context(fn () => array_filter(['user_id' => auth()->id()]));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
