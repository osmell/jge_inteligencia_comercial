<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\AppSetting;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Parámetros de la inteligencia comercial: valores por omisión en config/insights.php y valores vigentes en la base de datos.
 * Todos los usuarios con acceso los pueden consultar; solo quien tenga insights.manage los cambia, y cada cambio queda en Auditoría.
 */
class InsightParameters
{
    private const PREFIX = 'insights.';

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, array<string, mixed>> */
    public function definitions(): array
    {
        return (array) config('insights.parameters');
    }

    /** Valor vigente de un parámetro (entero). */
    public function get(string $key): int
    {
        $definition = $this->definitions()[$key] ?? throw new \InvalidArgumentException("Parámetro desconocido: {$key}");

        return $this->current()[$key] ?? (int) $definition['default'];
    }

    /** @return array<string, int> todos los valores vigentes */
    public function values(): array
    {
        $current = $this->current();
        $values = [];
        foreach ($this->definitions() as $key => $definition) {
            $values[$key] = $current[$key] ?? (int) $definition['default'];
        }

        return $values;
    }

    /** @return list<array<string, mixed>> */
    public function describe(): array
    {
        $current = $this->current();
        $rows = [];
        foreach ($this->definitions() as $key => $d) {
            $rows[] = ['key' => $key, 'group' => $d['group'], 'label' => $d['label'], 'unit' => $d['unit'], 'help' => $d['help'], 'min' => $d['min'], 'max' => $d['max'], 'default' => $d['default'], 'value' => $current[$key] ?? $d['default'], 'customized' => isset($current[$key]) && $current[$key] !== (int) $d['default']];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $changes  clave → nuevo valor
     * @return array<string, int> valores vigentes
     */
    public function update(User $user, array $changes): array
    {
        $errors = [];
        $clean = [];
        foreach ($changes as $key => $value) {
            $d = $this->definitions()[$key] ?? null;
            if ($d === null) {
                $errors["values.{$key}"] = 'Parámetro desconocido.';

                continue;
            }
            if (! is_int($value) && ! (is_string($value) && preg_match('/^\d{1,7}$/', $value))) {
                $errors["values.{$key}"] = 'Debe ser un número entero.';

                continue;
            }
            $value = (int) $value;
            if ($value < $d['min'] || $value > $d['max']) {
                $errors["values.{$key}"] = "Debe estar entre {$d['min']} y {$d['max']}.";

                continue;
            }
            $clean[$key] = $value;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        $before = $this->values();
        foreach ($clean as $key => $value) {
            AppSetting::updateOrCreate(['key' => self::PREFIX.$key], ['value' => (string) $value]);
        }
        $after = $this->values();
        $diff = array_keys(array_filter($after, fn (int $v, string $k) => $v !== $before[$k], ARRAY_FILTER_USE_BOTH));
        if ($diff !== []) {
            $this->audit->record('insights.parameters_updated', $user, old: array_intersect_key($before, array_flip($diff)), new: array_intersect_key($after, array_flip($diff)));
        }

        return $after;
    }

    /** Valores guardados en la base de datos (sin caché: el servicio puede vivir más que una petición, p. ej. en la cola). @return array<string, int> */
    private function current(): array
    {
        $values = [];
        foreach (AppSetting::query()->where('key', 'like', self::PREFIX.'%')->pluck('value', 'key') as $key => $value) {
            $values[substr($key, strlen(self::PREFIX))] = (int) $value;
        }

        return $values;
    }
}
