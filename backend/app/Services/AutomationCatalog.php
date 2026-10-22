<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Catálogo de automatizaciones (config/automations.php) con su estado y parámetros vigentes (base de datos), el interruptor
 * general y la ventana de avisos. Solo quien tenga automations.manage los cambia, y cada cambio queda en Auditoría.
 */
class AutomationCatalog
{
    private const MASTER = 'automations.enabled';

    private const WINDOW_START = 'automations.window_start';

    private const WINDOW_END = 'automations.window_end';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return (array) config('automations.automations');
    }

    public function exists(string $key): bool
    {
        return isset($this->definitions()[$key]);
    }

    /** Interruptor general: apagado, ninguna automatización corre (ni sola ni a mano). */
    public function masterEnabled(): bool
    {
        return AppSetting::query()->whereKey(self::MASTER)->value('value') !== '0';
    }

    /** @return array{start: int, end: int} */
    public function window(): array
    {
        $values = AppSetting::query()->whereIn('key', [self::WINDOW_START, self::WINDOW_END])->pluck('value', 'key');

        return ['start' => (int) ($values[self::WINDOW_START] ?? config('automations.window.start')), 'end' => (int) ($values[self::WINDOW_END] ?? config('automations.window.end'))];
    }

    public function enabled(string $key): bool
    {
        $stored = DB::table('automation_settings')->where('automation', $key)->value('enabled');

        return $stored === null ? (bool) $this->definitions()[$key]['default_enabled'] : (bool) $stored;
    }

    /** @return array<string, int> parámetros vigentes (los guardados sobre los valores por omisión) */
    public function params(string $key): array
    {
        $stored = json_decode((string) DB::table('automation_settings')->where('automation', $key)->value('params'), true) ?: [];
        $params = [];
        foreach ($this->definitions()[$key]['params'] as $name => $p) {
            $params[$name] = isset($stored[$name]) ? (int) $stored[$name] : (int) $p['default'];
        }

        return $params;
    }

    public function available(string $key): bool
    {
        return ($this->definitions()[$key]['handler'] ?? null) !== null;
    }

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        $last = DB::table('automation_runs')->orderByDesc('started_at')->orderByDesc('id')->get()->unique('automation')->keyBy('automation');
        $rows = [];
        foreach ($this->definitions() as $key => $d) {
            $params = $this->params($key);
            $run = $last[$key] ?? null;
            $rows[] = [
                'key' => $key, 'label' => $d['label'], 'description' => $d['description'], 'frequency' => $d['frequency'], 'respect_window' => $d['respect_window'], 'available' => $this->available($key), 'enabled' => $this->enabled($key),
                'params' => collect($d['params'])->map(fn (array $p, string $name) => ['name' => $name, 'label' => $p['label'], 'unit' => $p['unit'], 'help' => $p['help'], 'min' => $p['min'], 'max' => $p['max'], 'default' => $p['default'], 'value' => $params[$name]])->values()->all(),
                'last_run' => $run ? ['status' => $run->status, 'trigger' => $run->trigger, 'started_at' => $run->started_at, 'finished_at' => $run->finished_at, 'attempt' => $run->attempt, 'error' => $run->error, 'result' => json_decode((string) $run->result, true)] : null,
            ];
        }

        return $rows;
    }

    public function setMaster(User $user, bool $enabled): void
    {
        $before = $this->masterEnabled();
        AppSetting::updateOrCreate(['key' => self::MASTER], ['value' => $enabled ? '1' : '0']);
        if ($before !== $enabled) {
            $this->audit->record('automations.master_changed', $user, old: ['enabled' => $before], new: ['enabled' => $enabled]);
        }
    }

    /** @return array{start: int, end: int} */
    public function setWindow(User $user, int $start, int $end): array
    {
        if ($start < 0 || $end > 24 || $start >= $end) {
            throw ValidationException::withMessages(['window' => 'La ventana debe empezar antes de terminar, entre las 0 y las 24 horas.']);
        }
        $before = $this->window();
        AppSetting::updateOrCreate(['key' => self::WINDOW_START], ['value' => (string) $start]);
        AppSetting::updateOrCreate(['key' => self::WINDOW_END], ['value' => (string) $end]);
        if ($before !== ['start' => $start, 'end' => $end]) {
            $this->audit->record('automations.window_changed', $user, old: $before, new: ['start' => $start, 'end' => $end]);
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * @param  array<string, mixed>  $params  nombre → valor (solo los que se cambian)
     */
    public function update(User $user, string $key, ?bool $enabled, array $params): void
    {
        $definition = $this->definitions()[$key];
        $errors = [];
        $clean = [];
        foreach ($params as $name => $value) {
            $p = $definition['params'][$name] ?? null;
            if ($p === null) {
                $errors["params.{$name}"] = 'Parámetro desconocido.';
            } elseif (! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,9}$/', $value))) {
                $errors["params.{$name}"] = 'Debe ser un número entero.';
            } elseif ((int) $value < $p['min'] || (int) $value > $p['max']) {
                $errors["params.{$name}"] = "Debe estar entre {$p['min']} y {$p['max']}.";
            } else {
                $clean[$name] = (int) $value;
            }
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $before = ['enabled' => $this->enabled($key), 'params' => $this->params($key)];
        $newParams = $clean + $before['params'];
        $newEnabled = $enabled ?? $before['enabled'];
        DB::table('automation_settings')->updateOrInsert(['automation' => $key], ['enabled' => $newEnabled, 'params' => json_encode($newParams), 'updated_by' => $user->id, 'updated_at' => now()]);
        $after = ['enabled' => $newEnabled, 'params' => $newParams];
        if ($before !== $after) {
            $this->audit->record('automations.updated', $user, old: ['automation' => $key] + $before, new: ['automation' => $key] + $after);
        }
    }
}
