<?php

declare(strict_types=1);

namespace App\Providers;

use App\Contracts\WebhookTransport;
use App\Contracts\WhatsappMediaSource;
use App\Contracts\WhatsappMessaging;
use App\Integrations\GraphApiWhatsapp;
use App\Integrations\HttpWebhookTransport;
use App\Services\DataScope;
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
        //
    }
}
