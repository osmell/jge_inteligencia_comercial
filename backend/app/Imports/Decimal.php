<?php

namespace App\Imports;

/** Aritmética exacta con importes de 4 decimales usando enteros (sin float, sin bcmath). */
final class Decimal
{
    public const SCALE = 4;

    /** '12.3456' → 123456 */
    public static function units(string $decimal): int
    {
        $negative = str_starts_with($decimal, '-');
        [$int, $frac] = array_pad(explode('.', ltrim($decimal, '+-'), 2), 2, '');
        $value = (int) ($int.str_pad(substr($frac, 0, self::SCALE), self::SCALE, '0'));

        return $negative ? -$value : $value;
    }

    /** 123456 → '12.3456' */
    public static function format(int $units): string
    {
        $negative = $units < 0;
        $digits = str_pad((string) abs($units), self::SCALE + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($digits, 0, -self::SCALE).'.'.substr($digits, -self::SCALE);
    }
}
