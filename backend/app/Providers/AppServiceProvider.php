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
use App\Services\Dashboard;
use App\Services\DashboardService;
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

        // Secciones del dashboard, en el orden en que se muestran. Para agregar una: crear la clase y añadirla aquí.
        $this->app->tag([
            Dashboard\SalesSection::class, Dashboard\GoalsSection::class, Dashboard\RankingsSection::class, Dashboard\CompaniesSection::class,
            Dashboard\CreditSection::class, Dashboard\QuotationsSection::class, Dashboard\FollowupSection::class, Dashboard\ConversationsSection::class,
            Dashboard\InventorySection::class, Dashboard\InsightsSection::class,
        ], 'dashboard.sections');
        $this->app->when(DashboardService::class)->needs('$sections')->giveTagged('dashboard.sections');

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
