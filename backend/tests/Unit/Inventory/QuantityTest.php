<?php

declare(strict_types=1);

namespace Tests\Unit\Inventory;

use App\Inventory\Quantity;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QuantityTest extends TestCase
{
    /** @return array<string, array{string, int}> */
    public static function decimals(): array
    {
        return [
            'entero' => ['3', 300_000_000],
            'con decimales' => ['12.5', 1_250_000_000],
            'fracción pequeña' => ['0.00097656', 97_656],
            'negativo' => ['-3', -300_000_000],
            'espacios' => [' 2.5 ', 250_000_000],
            'redondea hacia arriba con más de 8 decimales' => ['1.234567895', 123_456_790],
            'trunca si el noveno decimal es menor que 5' => ['1.234567894', 123_456_789],
        ];
    }

    #[DataProvider('decimals')]
    public function test_units_converts_text_to_scaled_integer(string $text, int $expected): void
    {
        $this->assertSame($expected, Quantity::units($text));
    }

    #[DataProvider('invalidDecimals')]
    public function test_units_rejects_invalid_text(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::units($text);
    }

    /** @return array<string, array{string}> */
    public static function invalidDecimals(): array
    {
        return ['letras' => ['abc'], 'vacío' => [''], 'coma' => ['1,5'], 'demasiados enteros' => ['1234567890'], 'doble signo' => ['--1']];
    }

    public function test_format_and_display(): void
    {
        $this->assertSame('12.50000000', Quantity::format(1_250_000_000));
        $this->assertSame('0.00097656', Quantity::format(97_656));
        $this->assertSame('-0.00000005', Quantity::format(-5));
        $this->assertSame('0.00000000', Quantity::format(0));

        $this->assertSame('12.5', Quantity::display(1_250_000_000));
        $this->assertSame('3', Quantity::display(300_000_000));
        $this->assertSame('10', Quantity::display(1_000_000_000));
        $this->assertSame('0', Quantity::display(0));
        $this->assertSame('0.00097656', Quantity::display(97_656));
        $this->assertSame('-1.5', Quantity::display(-150_000_000));
    }

    public function test_format_is_the_inverse_of_units(): void
    {
        foreach (['0', '1', '12.5', '0.00000001', '9999999.99999999', '-4.25'] as $text) {
            $this->assertSame(Quantity::units($text), Quantity::units(Quantity::format(Quantity::units($text))), $text);
        }
    }

    /** @return array<string, array{int, int, int, int}> */
    public static function conversions(): array
    {
        return [
            'multiplica' => [100_000_000, 12, 1, 1_200_000_000],
            'mitad se redondea hacia arriba' => [3, 1, 2, 2],
            'mitad negativa se redondea en magnitud' => [-3, 1, 2, -2],
            'menos de la mitad baja' => [1, 1, 3, 0],
            'más de la mitad sube' => [2, 1, 3, 1],
            'cero' => [0, 7, 3, 0],
        ];
    }

    #[DataProvider('conversions')]
    public function test_convert_rounds_half_up_in_magnitude(int $units, int $num, int $den, int $expected): void
    {
        $this->assertSame($expected, Quantity::convert($units, $num, $den));
    }

    public function test_convert_detects_overflow(): void
    {
        $this->expectException(OverflowException::class);
        Quantity::convert(PHP_INT_MAX, 2, 1);
    }

    public function test_extend_multiplies_quantity_by_cost(): void
    {
        $this->assertSame(30_000, Quantity::extend(200_000_000, 15_000));          // 2 × 1,5000 = 3,0000
        $this->assertSame(1, Quantity::extend(50_000_000, 1));                      // 0,5 × 0,0001 = 0,00005 → sube a 0,0001
        $this->assertSame(0, Quantity::extend(1, 5_000));                           // casi nada → 0
        $this->assertSame(100_000_000_000, Quantity::extend(Quantity::MAX, 10_000)); // 10 millones × 1,0000 sin desbordar
        $this->assertSame(0, Quantity::extend(0, 10_000));
    }

    /** @return array<string, array{string, int, int}> */
    public static function factors(): array
    {
        return [
            'entero' => ['1', 1, 1],
            'con decimal' => ['2.5', 5, 2],
            'se reduce' => ['0.25', 1, 4],
            'medio' => ['0.5', 1, 2],
            'mínimo' => ['0.000001', 1, 1_000_000],
            'máximo' => ['100000', 100_000, 1],
        ];
    }

    #[DataProvider('factors')]
    public function test_factor_returns_reduced_fraction(string $text, int $num, int $den): void
    {
        $this->assertSame([$num, $den], Quantity::factor($text));
        $this->assertSame($text === '100000' ? '100000' : rtrim(rtrim($text, '0'), '.'), Quantity::factorText($num, $den));
    }

    #[DataProvider('invalidFactors')]
    public function test_factor_rejects_invalid_values(string $text): void
    {
        $this->expectException(InvalidArgumentException::class);
        Quantity::factor($text);
    }

    /** @return array<string, array{string}> */
    public static function invalidFactors(): array
    {
        return ['cero' => ['0'], 'sobre el máximo' => ['100001'], 'letras' => ['abc'], 'siete decimales' => ['1.1234567'], 'negativo' => ['-1']];
    }
}
