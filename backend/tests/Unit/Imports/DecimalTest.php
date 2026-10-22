<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use App\Imports\Decimal;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DecimalTest extends TestCase
{
    public function test_units_converts_to_four_decimals_without_rounding(): void
    {
        $this->assertSame(123_456, Decimal::units('12.3456'));
        $this->assertSame(-15_000, Decimal::units('-1.5'));
        $this->assertSame(20_000, Decimal::units('+2'));
        $this->assertSame(50_000, Decimal::units('5'));
        $this->assertSame(1, Decimal::units('0.00019'));
        $this->assertSame(0, Decimal::units('0'));
    }

    public function test_format_is_the_inverse_of_units(): void
    {
        $this->assertSame('12.3456', Decimal::format(123_456));
        $this->assertSame('-0.0005', Decimal::format(-5));
        $this->assertSame('0.0000', Decimal::format(0));
        $this->assertSame('1000.0000', Decimal::format(Decimal::units('1000')));
    }

    #[DataProvider('positives')]
    public function test_is_positive(?string $value, bool $expected): void
    {
        $this->assertSame($expected, Decimal::isPositive($value));
    }

    /** @return array<string, array{?string, bool}> */
    public static function positives(): array
    {
        return [
            'uno' => ['1', true],
            'centavo' => ['0.01', true],
            'diezmilésima' => ['0.0001', true],
            'cero' => ['0', false],
            'ceros con decimales' => ['0.000', false],
            'más cero' => ['+0', false],
            'negativo' => ['-1', false],
            'nulo' => [null, false],
        ];
    }

    public function test_is_negative(): void
    {
        $this->assertTrue(Decimal::isNegative('-0.5'));
        $this->assertFalse(Decimal::isNegative('1'));
        $this->assertFalse(Decimal::isNegative(null));
    }
}
