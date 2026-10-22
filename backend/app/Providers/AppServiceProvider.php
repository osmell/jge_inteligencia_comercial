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
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\RateLimiter;
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
        // Límite general de la API: por persona (o por dirección si no ha iniciado sesión). Holgado para el uso normal (consultas periódicas
        // de avisos y chat) y suficiente para frenar el recorrido masivo de datos.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute((int) config('security.api_per_minute'))->by($request->user()?->id ? 'u'.$request->user()->id : 'ip'.$request->ip()));

        // Un trabajo en cola que agota sus reintentos queda en el registro con contexto estructurado (y en `failed_jobs`).
        Queue::failing(function (JobFailed $event): void {
            Log::error('queue.job_failed', ['job' => $event->job->resolveName(), 'queue' => $event->job->getQueue(), 'attempts' => $event->job->attempts(), 'exception' => $event->exception->getMessage()]);
        });

        // Documentación de la API (/docs/api): solo administración, también fuera de producción.
        Gate::define('viewApiDocs', fn (?User $user) => $user?->is_active === true && $user->role->slug === Role::ADMINISTRADOR);

        // Análisis de toda la compañía (concentración, estacionalidad…): no disponibles para vendedores.
        Gate::define('view-company-analysis', fn (User $user) => $user->role->slug !== Role::VENDEDOR
            ? Response::allow()
            : Response::deny('Este análisis es de toda la compañía y no está disponible para vendedores.'));
    }
}
