<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use App\Imports\ErrorCollector;
use App\Imports\HeaderMapper;
use App\Imports\Inventory\InventoryUnits;
use App\Imports\SpreadsheetReader;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

final class ImportHelpersTest extends TestCase
{
    public function test_header_normalization_ignores_case_accents_and_symbols(): void
    {
        $this->assertSame('codigodecliente', HeaderMapper::normalize('Código de Cliente'));
        $this->assertSame('importenetoq', HeaderMapper::normalize('Importe  Neto (Q)'));
        $this->assertSame('', HeaderMapper::normalize('---'));
    }

    public function test_unit_text_normalization(): void
    {
        $this->assertSame('caja grande', InventoryUnits::normalize("  CAJA \t Grande "));
        $this->assertSame('metro cuadrado', InventoryUnits::normalize('Metro Cuadrádo'));
    }

    public function test_blank_rows_are_detected(): void
    {
        $this->assertTrue(SpreadsheetReader::isBlank([]));
        $this->assertTrue(SpreadsheetReader::isBlank([null, '  ', '']));
        $this->assertFalse(SpreadsheetReader::isBlank([null, 0]));
        $this->assertFalse(SpreadsheetReader::isBlank(['a']));
        $this->assertFalse(SpreadsheetReader::isBlank([false]));
    }

    public function test_error_values_are_shown_as_short_text(): void
    {
        $this->assertNull(ErrorCollector::display(null));
        $this->assertNull(ErrorCollector::display(['a']));
        $this->assertSame('5', ErrorCollector::display(5));
        $this->assertSame('1', ErrorCollector::display(true));
        $this->assertSame('2026-03-01 08:30:00', ErrorCollector::display(new DateTimeImmutable('2026-03-01 08:30:00')));
        $this->assertSame(100, mb_strlen((string) ErrorCollector::display(str_repeat('ñ', 250))));
    }
}
