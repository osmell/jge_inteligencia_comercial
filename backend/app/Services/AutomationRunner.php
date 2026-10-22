<?php

declare(strict_types=1);

namespace App\Services;

use App\Automations\Handler;
use App\Models\AutomationRun;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

/**
 * Ejecuta las automatizaciones. El programador llama a {@see tick} cada pocos minutos: corre las que están activas y les toca (su hora
 * llegó, no se ejecutaron ya en el periodo y, si envían avisos, estamos dentro de la ventana), reintenta las fallidas y marca las
 * interrumpidas. Cada ejecución queda registrada con su resultado o su error. Con el interruptor general apagado no corre nada.
 * Una ejecución programada se identifica por su periodo (día, semana u hora): el programador puede correr varias veces sin repetirla.
 */
class AutomationRunner
{
    public function __construct(private readonly AutomationCatalog $catalog, private readonly AuditLogger $audit) {}

    /**
     * @return array{started: int, retried: int, interrupted: int}
     */
    public function tick(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $result = ['started' => 0, 'retried' => 0, 'interrupted' => 0];
        if (! $this->catalog->masterEnabled()) {
            return $result;
        }

        $stale = AutomationRun::query()->where('status', AutomationRun::RUNNING)->where('started_at', '<', $now->subMinutes((int) config('automations.stale_after_minutes')))->get();
        foreach ($stale as $run) {
            $this->fail($run, 'La ejecución se interrumpió antes de terminar.', $now);
            $result['interrupted']++;
        }

        foreach ($this->catalog->definitions() as $key => $definition) {
            if (! $this->catalog->available($key) || ! $this->catalog->enabled($key)) {
                continue;
            }
            // Reintento de una ejecución fallida que ya puede repetirse.
            $retry = AutomationRun::query()->where(['automation' => $key, 'status' => AutomationRun::FAILED])->whereNotNull('next_retry_at')->where('next_retry_at', '<=', $now)->orderBy('id')->first();
            if ($retry !== null && $this->withinWindow($definition, $now)) {
                $this->execute($retry, $now, increment: true);
                $result['retried']++;

                continue;
            }
            $period = $this->duePeriod($key, $definition, $now);
            if ($period === null || AutomationRun::query()->where(['automation' => $key, 'period_key' => $period])->exists()) {
                continue;
            }
            try {
                $run = AutomationRun::create(['automation' => $key, 'trigger' => 'schedule', 'period_key' => $period, 'status' => AutomationRun::RUNNING, 'attempt' => 1, 'started_at' => $now]);
            } catch (UniqueConstraintViolationException) {
                continue;       // otro proceso la tomó primero
            }
            $this->execute($run, $now);
            $result['started']++;
        }

        return $result;
    }

    /** Ejecución manual de un administrador (no depende de la hora, pero sí del interruptor general). */
    public function runNow(string $key, User $user, ?CarbonImmutable $now = null): AutomationRun
    {
        $now ??= CarbonImmutable::now();
        if (! $this->catalog->exists($key)) {
            abort(404, 'Automatización no encontrada.');
        }
        abort_unless($this->catalog->available($key), 422, 'Esta automatización todavía está en preparación.');
        abort_unless($this->catalog->masterEnabled(), 422, 'Las automatizaciones están pausadas con el interruptor general.');

        $run = AutomationRun::create(['automation' => $key, 'trigger' => 'manual', 'status' => AutomationRun::RUNNING, 'attempt' => 1, 'started_at' => $now, 'user_id' => $user->id]);
        $this->audit->record('automations.run_manual', $user, new: ['automation' => $key]);
        $this->execute($run, $now, retries: false);

        return $run->refresh();
    }

    private function execute(AutomationRun $run, CarbonImmutable $now, bool $increment = false, bool $retries = true): void
    {
        if ($increment) {
            $run->forceFill(['attempt' => $run->attempt + 1, 'status' => AutomationRun::RUNNING, 'started_at' => $now, 'finished_at' => null, 'next_retry_at' => null, 'error' => null])->save();
        }
        try {
            $handler = app($this->catalog->definitions()[$run->automation]['handler']);
            /** @var Handler $handler */
            $result = $handler->run($this->catalog->params($run->automation));
            $run->forceFill(['status' => AutomationRun::OK, 'finished_at' => CarbonImmutable::now(), 'result' => $result, 'next_retry_at' => null, 'error' => null])->save();
        } catch (Throwable $e) {
            report($e);
            $this->fail($run, mb_substr($e->getMessage(), 0, 900), $now, $retries);
        }
    }

    private function fail(AutomationRun $run, string $message, CarbonImmutable $now, bool $retries = true): void
    {
        $waits = (array) config('automations.retry_minutes');
        $wait = $retries && $run->trigger === 'schedule' ? ($waits[$run->attempt - 1] ?? null) : null;
        $run->forceFill(['status' => AutomationRun::FAILED, 'finished_at' => CarbonImmutable::now(), 'error' => $message, 'next_retry_at' => $wait === null ? null : $now->addMinutes((int) $wait)])->save();
    }

    /**
     * Periodo que le toca ejecutar ahora a una automatización (null si todavía no es su hora o está fuera de la ventana).
     *
     * @param  array<string, mixed>  $definition
     */
    private function duePeriod(string $key, array $definition, CarbonImmutable $now): ?string
    {
        if (! $this->withinWindow($definition, $now)) {
            return null;
        }
        $hour = $this->catalog->params($key)['hour'] ?? 0;

        return match ($definition['frequency']) {
            'weekly' => $now >= $now->startOfWeek()->setTime($hour, 0) ? $now->format('o-\WW') : null,
            'hourly' => $now->format('Y-m-d\TH'),
            default => $now >= $now->startOfDay()->setTime($hour, 0) ? $now->format('Y-m-d') : null,
        };
    }

    /** @param  array<string, mixed>  $definition */
    private function withinWindow(array $definition, CarbonImmutable $now): bool
    {
        if (! $definition['respect_window']) {
            return true;
        }
        $window = $this->catalog->window();

        return $now->dayOfWeek !== CarbonImmutable::SUNDAY && $now->hour >= $window['start'] && $now->hour < $window['end'];
    }
}
