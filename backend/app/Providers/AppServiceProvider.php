<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\WebhookTransport;
use App\Contracts\WhatsappMediaSource;
use App\Contracts\WhatsappMessaging;
use App\Integrations\GraphApiWhatsapp;
use App\Integrations\HttpWebhookTransport;
use App\Models\Role;
use App\Models\User;
use App\Services\DataScope;
use Illuminate\Auth\Access\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(DataScope::class);

        // Puertos hacia sistemas externos: para cambiar de proveedor o simular en pruebas basta cambiar este enlace.
        $this->app->bind(WhatsappMessaging::class, GraphApiWhatsapp::class);
        $this->app->bind(WhatsappMediaSource::class, GraphApiWhatsapp::class);
        $this->app->bind(WebhookTransport::class, HttpWebhookTransport::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Análisis de toda la compañía (concentración, estacionalidad…): no disponibles para vendedores.
        Gate::define('view-company-analysis', fn (User $user) => $user->role->slug !== Role::VENDEDOR
            ? Response::allow()
            : Response::deny('Este análisis es de toda la compañía y no está disponible para vendedores.'));
    }
}
