<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\AutomationRun;
use App\Models\WebhookDelivery;
use App\Models\WhatsappMessage;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Throwable;

/**
 * Estado operativo en una sola salida, pensado para un monitor o un cron: sale con código 1 si algún indicador pasa su umbral.
 * Indicadores: base de datos, trabajos fallidos, cola pendiente y envíos/automatizaciones fallidos en las últimas horas.
 */
#[Signature('ops:status {--hours=24 : Ventana para contar fallos recientes} {--max-failed-jobs=0 : Trabajos fallidos tolerados} {--max-queue=500 : Trabajos pendientes tolerados} {--max-failures=0 : Envíos o ejecuciones fallidos tolerados en la ventana} {--json : Salida en JSON}')]
#[Description('Muestra el estado operativo (base de datos, colas, envíos y automatizaciones) y falla si algo pasa su umbral')]
class OpsStatus extends Command
{
    public function handle(): int
    {
        $since = now()->subHours((int) $this->option('hours'));
        $checks = [];

        try {
            DB::select('select 1');
            $checks['database'] = ['value' => 'ok', 'ok' => true];
        } catch (Throwable $e) {
            $checks['database'] = ['value' => 'sin conexión: '.mb_substr($e->getMessage(), 0, 120), 'ok' => false];
        }

        // En producción: sin modo de depuración (mostraría trazas y variables) y con cookie de sesión solo por HTTPS.
        $production = app()->isProduction();
        $checks['debug_off'] = ['value' => $production ? (config('app.debug') ? 'APP_DEBUG=true' : 'ok') : 'no aplica (no es producción)', 'ok' => ! $production || ! config('app.debug')];
        $checks['secure_cookie'] = ['value' => $production ? (config('session.secure') ? 'ok' : 'SESSION_SECURE_COOKIE desactivado') : 'no aplica (no es producción)', 'ok' => ! $production || config('session.secure') === true];

        if ($checks['database']['ok']) {
            $failedJobs = DB::table('failed_jobs')->count();
            $checks['failed_jobs'] = ['value' => $failedJobs, 'ok' => $failedJobs <= (int) $this->option('max-failed-jobs')];
            $queued = Queue::size();
            $checks['queue_pending'] = ['value' => $queued, 'ok' => $queued <= (int) $this->option('max-queue')];
            $max = (int) $this->option('max-failures');
            foreach ([
                'whatsapp_failed' => WhatsappMessage::query()->where('status', WhatsappMessage::FAILED)->where('created_at', '>=', $since)->count(),
                'webhooks_failed' => WebhookDelivery::query()->where('status', WebhookDelivery::FAILED)->where('created_at', '>=', $since)->count(),
                'automations_failed' => AutomationRun::query()->where('status', AutomationRun::FAILED)->where('started_at', '>=', $since)->count(),
            ] as $name => $count) {
                $checks[$name] = ['value' => $count, 'ok' => $count <= $max];
            }
        }

        $ok = collect($checks)->every(fn (array $c) => $c['ok']);
        if ($this->option('json')) {
            $this->line((string) json_encode(['ok' => $ok, 'since' => $since->toIso8601String(), 'checks' => $checks], JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($checks as $name => $c) {
                $this->line(sprintf('%s %-20s %s', $c['ok'] ? '✓' : '✗', $name, $c['value']));
            }
            $ok ? $this->info('Todo en orden.') : $this->error('Hay indicadores fuera de su umbral.');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
