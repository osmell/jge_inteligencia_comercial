<?php

declare(strict_types=1);

use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsurePermission;
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
    ->withBroadcasting(__DIR__.'/../routes/channels.php', ['prefix' => 'api/v1', 'middleware' => ['api', 'auth:sanctum', 'active']])
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->statefulApi();
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
        $middleware->alias([
            'permission' => EnsurePermission::class,
            'active' => EnsureUserIsActive::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Quién estaba conectado cuando ocurrió el error (el identificador de petición ya viaja en el contexto compartido).
        $exceptions->context(fn () => array_filter(['user_id' => auth()->id()]));
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
