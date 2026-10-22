<?php

declare(strict_types=1);

namespace App\Imports;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Fila cruda con lectores tipados. Cada lector registra el error (fila + columna) y devuelve null si falla.
 * Al terminar, `failed()` indica si la fila tuvo errores.
 */
final class ImportRow
{
    private bool $failed = false;

    private bool $skipped = false;

    /**
     * @param  array<string, mixed>  $cells  clave de columna => valor de la celda
     * @param  array<string, string>  $labels  clave de columna => encabezado canónico
     */
    public function __construct(
        public readonly int $number,
        private readonly array $cells,
        private readonly ErrorCollector $errors,
        private readonly array $labels,
    ) {}

    public function failed(): bool
    {
        return $this->failed;
    }

    /** La fila se descarta a propósito (no es un error): no se agrupa ni se importa. */
    public function skipped(): bool
    {
        return $this->skipped;
    }

    public function skip(string $key, string $code, string $message): void
    {
        $this->skipped = true;
        $this->warn($key, $code, $message);
    }

    /** Si la columna viene en el archivo (una columna opcional ausente no se toca; una presente y vacía borra el dato). */
    public function has(string $key): bool
    {
        return array_key_exists($key, $this->cells);
    }

    public function raw(string $key): mixed
    {
        return $this->cells[$key] ?? null;
    }

    public function label(string $key): string
    {
        return $this->labels[$key] ?? $key;
    }

    /** Registra un error propio del tipo (regla de negocio) sobre una columna de esta fila. */
    public function fail(string $key, string $code, string $message): void
    {
        $this->failed = true;
        $this->errors->error($code, $message, $this->number, $this->label($key), $this->raw($key));
    }

    public function warn(string $key, string $code, string $message): void
    {
        $this->errors->warning($code, $message, $this->number, $this->label($key), $this->raw($key));
    }

    public function text(string $key, bool $required = true): ?string
    {
        $value = $this->raw($key);

        if ($this->blank($value)) {
            return $this->missing($key, $required);
        }
        if ($value instanceof DateTimeInterface || is_bool($value)) {
            $this->fail($key, 'invalid_text', 'Se esperaba texto.');

            return null;
        }

        return $this->clean($this->numberAsString($value));
    }

    /**
     * Código (cliente, producto…): siempre texto. Si Excel lo entregó como número se avisa,
     * porque los ceros iniciales ya se perdieron; la columna debe formatearse como texto.
     */
    public function code(string $key, bool $required = true, bool $warnNumeric = true): ?string
    {
        $value = $this->raw($key);

        if ($this->blank($value)) {
            return $this->missing($key, $required);
        }
        if ($value instanceof DateTimeInterface || is_bool($value)) {
            $this->fail($key, 'invalid_code', 'Se esperaba un código de texto.');

            return null;
        }
        if (is_float($value) && floor($value) !== $value) {
            $this->fail($key, 'invalid_code', 'El código no puede tener decimales.');

            return null;
        }
        if ($warnNumeric && (is_int($value) || is_float($value))) {
            $this->warn($key, 'numeric_code', 'El código llegó como número; si tenía ceros iniciales se perdieron. Formatee la columna como texto en Excel.');
        }

        return $this->clean($this->numberAsString($value));
    }

    /**
     * Número decimal como texto con `$scale` decimales exactos (punto decimal, sin separador de miles).
     * Los valores numéricos de Excel se redondean a `$scale` decimales; el texto con más decimales se rechaza.
     */
    public function decimal(string $key, bool $required = true, int $scale = 4): ?string
    {
        $value = $this->raw($key);

        if ($this->blank($value)) {
            return $this->missing($key, $required);
        }

        if (is_int($value) || is_float($value)) {
            if (! is_finite((float) $value) || abs((float) $value) >= 1e14) {
                $this->fail($key, 'out_of_range', 'El número está fuera del rango permitido.');

                return null;
            }

            return number_format(round((float) $value, $scale), $scale, '.', '');
        }

        if (! is_string($value) || ! preg_match('/^[+-]?\d+(\.\d+)?$/', $text = preg_replace('/[\s\x{00A0}]/u', '', $value))) {
            $this->fail($key, 'invalid_number', 'No es un número válido (use punto decimal y sin separador de miles).');

            return null;
        }
        [$int, $frac] = array_pad(explode('.', ltrim($text, '+-'), 2), 2, '');
        if (strlen($frac) > $scale) {
            $this->fail($key, 'too_many_decimals', "El número tiene más de {$scale} decimales.");

            return null;
        }
        if (strlen(ltrim($int, '0')) > 14) {
            $this->fail($key, 'out_of_range', 'El número está fuera del rango permitido.');

            return null;
        }
        $negative = str_starts_with($text, '-') && (trim($int.$frac, '0') !== '');

        return ($negative ? '-' : '').(ltrim($int, '0') ?: '0').'.'.str_pad($frac, $scale, '0');
    }

    /**
     * Fecha: celda de fecha de Excel o texto en alguno de los formatos indicados (en el orden dado).
     *
     * @param  list<string>  $formats  formatos de DateTimeImmutable::createFromFormat
     */
    public function date(string $key, array $formats, bool $required = true): ?DateTimeImmutable
    {
        $value = $this->raw($key);

        if ($this->blank($value)) {
            return $this->missing($key, $required);
        }
        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }
        if (is_string($value)) {
            foreach ($formats as $format) {
                $date = DateTimeImmutable::createFromFormat('!'.$format, trim($value));
                $errors = DateTimeImmutable::getLastErrors();
                if ($date && (! $errors || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $date;
                }
            }
        }

        $this->fail($key, 'invalid_date', 'No es una fecha válida (formatos aceptados: '.implode(', ', $formats).').');

        return null;
    }

    private function missing(string $key, bool $required): null
    {
        if ($required) {
            $this->fail($key, 'required', 'El valor es obligatorio.');
        }

        return null;
    }

    private function blank(mixed $value): bool
    {
        return $value === null || (is_string($value) && trim($value) === '');
    }

    /** Quita espacios de los extremos y caracteres de control invisibles, salvo saltos de línea y tabuladores (p. ej., un separador de unidad pegado al copiar del sistema de origen). */
    private function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $value));
    }

    private function numberAsString(mixed $value): string
    {
        if (is_float($value) && floor($value) === $value && abs($value) < 1e15) {
            return (string) (int) $value;
        }

        return (string) $value;
    }
}
