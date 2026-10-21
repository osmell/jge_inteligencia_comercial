<?php

namespace App\Inventory;

use InvalidArgumentException;
use OverflowException;

/**
 * Cantidades de inventario con 8 decimales como enteros (sin float): las ventas traen fracciones como 0,00097656.
 * Una cantidad en unidad base no puede superar 10 millones (el producto de factor × cantidad cabe en un entero de 64 bits).
 */
final class Quantity
{
    public const SCALE = 8;

    public const FACTOR_SCALE = 6;

    /** Máximo en unidades escaladas: 10.000.000 de unidades base. */
    public const MAX = 1_000_000_000_000_000;

    /** Mayor factor de equivalencia aceptado. */
    public const MAX_FACTOR = 100_000;

    /** '12.5' → 1250000000. Más de 8 decimales se redondean (mitad hacia arriba). */
    public static function units(string $decimal): int
    {
        if (! preg_match('/^(-?)(\d{1,9})(?:\.(\d+))?$/', trim($decimal), $m)) {
            throw new InvalidArgumentException('Cantidad no válida.');
        }
        $frac = $m[3] ?? '';
        $value = (int) ($m[2].str_pad(substr($frac, 0, self::SCALE), self::SCALE, '0'));
        if (strlen($frac) > self::SCALE && (int) $frac[self::SCALE] >= 5) {
            $value++;
        }

        return $m[1] === '-' ? -$value : $value;
    }

    /** 1250000000 → '12.50000000' */
    public static function format(int $units): string
    {
        $digits = str_pad((string) abs($units), self::SCALE + 1, '0', STR_PAD_LEFT);

        return ($units < 0 ? '-' : '').substr($digits, 0, -self::SCALE).'.'.substr($digits, -self::SCALE);
    }

    /** Para mostrar: sin ceros sobrantes ('12.5', '0.00097656', '3'). */
    public static function display(int $units): string
    {
        $text = rtrim(rtrim(self::format($units), '0'), '.');

        return $text === '' || $text === '-' ? '0' : $text;
    }

    /** Multiplica por num/den con redondeo de mitad hacia arriba (en magnitud), sin pasar por float. */
    public static function convert(int $units, int $num, int $den): int
    {
        $abs = abs($units);
        $whole = intdiv($abs, $den);
        if ($num > 0 && $whole > intdiv(PHP_INT_MAX, $num)) {
            throw new OverflowException('La cantidad convertida es demasiado grande.');
        }
        $rest = ($abs % $den) * $num;
        $part = intdiv($rest, $den) + (2 * ($rest % $den) >= $den ? 1 : 0);
        $result = $whole * $num + $part;

        return $units < 0 ? -$result : $result;
    }

    /**
     * Factor decimal positivo (hasta 6 decimales) como fracción reducida.
     *
     * @return array{0: int, 1: int} [numerador, denominador]
     */
    public static function factor(string $decimal): array
    {
        if (! preg_match('/^(\d{1,6})(?:\.(\d{1,'.self::FACTOR_SCALE.'}))?$/', trim($decimal), $m)) {
            throw new InvalidArgumentException('El factor debe ser un número positivo con hasta 6 decimales.');
        }
        $frac = $m[2] ?? '';
        $num = (int) ($m[1].$frac);
        $den = 10 ** strlen($frac);
        if ($num <= 0 || $num > self::MAX_FACTOR * $den) {
            throw new InvalidArgumentException('El factor debe ser mayor que cero y no pasar de '.self::MAX_FACTOR.'.');
        }
        $gcd = self::gcd($num, $den);

        return [intdiv($num, $gcd), intdiv($den, $gcd)];
    }

    /** Fracción → decimal exacto para mostrar (el denominador siempre divide a 10^6). */
    public static function factorText(int $num, int $den): string
    {
        $scaled = $num * intdiv(10 ** self::FACTOR_SCALE, $den);
        $text = rtrim(rtrim(substr_replace(str_pad((string) $scaled, self::FACTOR_SCALE + 1, '0', STR_PAD_LEFT), '.', -self::FACTOR_SCALE, 0), '0'), '.');

        return $text;
    }

    private static function gcd(int $a, int $b): int
    {
        while ($b !== 0) {
            [$a, $b] = [$b, $a % $b];
        }

        return $a;
    }
}
