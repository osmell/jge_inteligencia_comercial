<?php

declare(strict_types=1);

namespace App\Services;

/** Tramos de antigüedad de la cartera. La edad se cuenta desde la fecha de la factura (el archivo no trae vencimiento). */
class CreditAging
{
    public const CURRENT = 'current';

    /** @return list<int> */
    public static function limits(): array
    {
        $limits = array_values(array_unique(array_map('intval', config('credit.aging_limits', [30, 60, 90, 120]))));
        sort($limits);

        return $limits;
    }

    /** Claves de los tramos en orden: «current», «1-30», «31-60»…, «121+». @return list<string> */
    public static function buckets(): array
    {
        $keys = [self::CURRENT];
        $from = 1;
        foreach (self::limits() as $limit) {
            $keys[] = "{$from}-{$limit}";
            $from = $limit + 1;
        }
        $keys[] = "{$from}+";

        return $keys;
    }

    public static function bucket(int $ageDays): string
    {
        if ($ageDays < 1) {
            return self::CURRENT;
        }
        $from = 1;
        foreach (self::limits() as $limit) {
            if ($ageDays <= $limit) {
                return "{$from}-{$limit}";
            }
            $from = $limit + 1;
        }

        return "{$from}+";
    }
}
