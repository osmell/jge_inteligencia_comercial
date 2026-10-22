<?php

declare(strict_types=1);

namespace App\Imports;

use DateTimeInterface;

/** Acumula errores y advertencias: cuenta todos pero guarda detalle solo hasta un límite. */
final class ErrorCollector
{
    public int $errors = 0;

    public int $warnings = 0;

    /** @var list<array<string, mixed>> */
    private array $stored = [];

    private int $storedErrors = 0;

    private int $storedWarnings = 0;

    public function __construct(private readonly int $maxStored) {}

    public function error(string $code, string $message, ?int $row = null, ?string $column = null, mixed $value = null): void
    {
        $this->errors++;
        if ($this->storedErrors < $this->maxStored) {
            $this->storedErrors++;
            $this->stored[] = $this->record('error', $code, $message, $row, $column, $value);
        }
    }

    public function warning(string $code, string $message, ?int $row = null, ?string $column = null, mixed $value = null): void
    {
        $this->warnings++;
        if ($this->storedWarnings < $this->maxStored) {
            $this->storedWarnings++;
            $this->stored[] = $this->record('warning', $code, $message, $row, $column, $value);
        }
    }

    public function hasErrors(): bool
    {
        return $this->errors > 0;
    }

    /** @return list<array<string, mixed>> ordenados por fila */
    public function stored(): array
    {
        $rows = $this->stored;
        usort($rows, fn ($a, $b) => [$a['row_number'] ?? 0, $a['severity']] <=> [$b['row_number'] ?? 0, $b['severity']]);

        return $rows;
    }

    /** @return array<string, mixed> */
    private function record(string $severity, string $code, string $message, ?int $row, ?string $column, mixed $value): array
    {
        return [
            'row_number' => $row,
            'column' => $column,
            'code' => $code,
            'severity' => $severity,
            'message' => $message,
            'value' => self::display($value),
        ];
    }

    public static function display(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }
        if (is_scalar($value)) {
            return mb_substr((string) $value, 0, 100);
        }

        return null;
    }
}
