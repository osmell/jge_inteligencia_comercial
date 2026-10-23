<?php

declare(strict_types=1);

namespace App\Services\Reports;

use InvalidArgumentException;

/** Semáforo de un indicador (verde/amarillo/rojo) según los umbrales de `config/reports.php`. */
class Semaphore
{
    public const GOOD = 'good';

    public const WARN = 'warn';

    public const BAD = 'bad';

    /** Sin dato: no hay con qué evaluar (por ejemplo, un vendedor sin meta). */
    public const NONE = 'none';

    public function level(string $indicator, ?float $value): string
    {
        $rule = config("reports.thresholds.$indicator");
        if (! is_array($rule)) {
            throw new InvalidArgumentException("Indicador sin umbrales: $indicator");
        }
        if ($value === null) {
            return self::NONE;
        }

        if ($rule['higher_is_better']) {
            return $value < $rule['bad'] ? self::BAD : ($value < $rule['warn'] ? self::WARN : self::GOOD);
        }

        return $value >= $rule['bad'] ? self::BAD : ($value >= $rule['warn'] ? self::WARN : self::GOOD);
    }

    /** @return list<string> */
    public function indicators(): array
    {
        return array_map('strval', array_keys(config('reports.thresholds')));
    }
}
