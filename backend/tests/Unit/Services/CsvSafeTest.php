<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\Reports\CsvSafe;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Protege contra la inyección de fórmulas en Excel con textos que vienen de archivos importados. */
final class CsvSafeTest extends TestCase
{
    #[DataProvider('dangerous')]
    public function test_formula_prefixes_are_neutralised(string $value): void
    {
        $this->assertSame("'".$value, CsvSafe::value($value));
    }

    /** @return array<string, array{string}> */
    public static function dangerous(): array
    {
        return ['igual' => ['=SUM(A1)'], 'más' => ['+1'], 'menos' => ['-1'], 'arroba' => ['@cmd'], 'tabulador' => ["\tx"], 'retorno' => ["\rx"]];
    }

    public function test_safe_values_are_untouched(): void
    {
        $this->assertSame('Cliente Uno', CsvSafe::value('Cliente Uno'));
        $this->assertSame('12=3', CsvSafe::value('12=3'));
        $this->assertSame('', CsvSafe::value(''));
        $this->assertNull(CsvSafe::value(null));
    }
}
