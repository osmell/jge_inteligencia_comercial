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

    /** Texto decimal (de cualquier número de decimales) mayor que cero: sin signo menos y con algún dígito distinto de cero. */
    public static function isPositive(?string $decimal): bool
    {
        return $decimal !== null && ! str_starts_with($decimal, '-') && trim(str_replace('.', '', $decimal), '0+') !== '';
    }

    public static function isNegative(?string $decimal): bool
    {
        return $decimal !== null && str_starts_with($decimal, '-');
    }

    /** 123456 → '12.3456' */
    public static function format(int $units): string
    {
        $negative = $units < 0;
        $digits = str_pad((string) abs($units), self::SCALE + 1, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').substr($digits, 0, -self::SCALE).'.'.substr($digits, -self::SCALE);
    }
}
