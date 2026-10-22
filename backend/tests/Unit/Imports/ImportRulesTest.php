<?php

declare(strict_types=1);

namespace Tests\Unit\Imports;

use App\Imports\ErrorCollector;
use App\Imports\ImportRow;
use App\Imports\Rules\CreditAmounts;
use App\Imports\Rules\RowRules;
use App\Imports\Rules\SalesAmounts;
use DateTimeImmutable;
use Tests\TestCase;

/** Reglas de validación de fila compartidas por los tipos de importación (se prueban sin archivo ni base de datos). */
final class ImportRulesTest extends TestCase
{
    private ErrorCollector $errors;

    protected function setUp(): void
    {
        parent::setUp();
        config(['imports.amount_tolerance' => 0.01, 'imports.sales.tax_rates' => []]);
        $this->errors = new ErrorCollector(100);
    }

    /** @param  array<string, mixed>  $cells */
    private function row(array $cells = []): ImportRow
    {
        return new ImportRow(2, $cells, $this->errors, ['net_total' => 'Total Neto', 'tax_total' => 'Total Impuesto', 'sale_plus_tax' => 'Venta + Impuesto', 'total' => 'Total', 'subtotal' => 'Sub Total', 'discount_total' => 'Descuento']);
    }

    /** @return list<string> */
    private function codes(): array
    {
        return array_column($this->errors->stored(), 'code');
    }

    public function test_limit_accepts_text_within_the_maximum_and_rejects_longer_text(): void
    {
        $row = $this->row();

        $this->assertSame('abc', RowRules::limit($row, 'name', 'abc', 5));
        $this->assertNull(RowRules::limit($row, 'name', null));
        $this->assertFalse($row->failed());

        $this->assertNull(RowRules::limit($row, 'name', str_repeat('x', 6), 5));
        $this->assertTrue($row->failed());
        $this->assertSame(['too_long'], $this->codes());
    }

    public function test_limit_defaults_to_255_characters(): void
    {
        $row = $this->row();

        $this->assertNotNull(RowRules::limit($row, 'name', str_repeat('x', 255)));
        $this->assertNull(RowRules::limit($row, 'name', str_repeat('x', 256)));
    }

    public function test_issued_dates_only_warn(): void
    {
        $row = $this->row();
        RowRules::issuedDate($row, new DateTimeImmutable('today'));
        $this->assertSame([], $this->codes());

        RowRules::issuedDate($row, new DateTimeImmutable('+10 days'));
        RowRules::issuedDate($row, new DateTimeImmutable('1999-12-31'));

        $this->assertSame(['future_date', 'old_date'], $this->codes());
        $this->assertFalse($row->failed());
        $this->assertSame(0, $this->errors->errors);
        $this->assertSame(2, $this->errors->warnings);
    }

    /** @return array<string, string|null> */
    private function amounts(string $net = '100.0000', string $tax = '13.0000', string $plus = '113.0000'): array
    {
        return ['unit_cost' => '10.0000', 'line_cost_total' => '10.0000', 'unit_price' => '100.0000', 'normal_price' => '100.0000', 'net_total' => $net, 'tax_total' => $tax, 'sale_plus_tax' => $plus];
    }

    public function test_a_consistent_sales_line_passes(): void
    {
        $row = $this->row();
        SalesAmounts::check($row, '1.0000', $this->amounts());

        $this->assertFalse($row->failed());
    }

    public function test_sales_total_must_equal_net_plus_tax(): void
    {
        $row = $this->row();
        SalesAmounts::check($row, '1.0000', $this->amounts(plus: '120.0000'));

        $this->assertTrue($row->failed());
        $this->assertContains('amount_mismatch', $this->codes());
    }

    public function test_sales_lines_reject_non_positive_units_and_negative_amounts(): void
    {
        $row = $this->row();
        SalesAmounts::check($row, '0', $this->amounts(net: '-100.0000', plus: '-87.0000'));

        $this->assertContains('non_positive_units', $this->codes());
        $this->assertContains('negative_amount', $this->codes());
    }

    public function test_sales_tax_rate_is_checked_only_when_rates_are_configured(): void
    {
        config(['imports.sales.tax_rates' => [0.13]]);
        $row = $this->row();
        SalesAmounts::check($row, '1.0000', $this->amounts(tax: '20.0000', plus: '120.0000'));

        $this->assertContains('tax_rate_mismatch', $this->codes());
    }

    public function test_sales_subtotal_minus_discount_must_equal_net(): void
    {
        $row = $this->row();
        SalesAmounts::check($row, '1.0000', $this->amounts(), ['subtotal' => '150.0000', 'discount_total' => '10.0000']);

        $this->assertContains('subtotal_mismatch', $this->codes());
    }

    public function test_an_empty_sales_line_is_recognised(): void
    {
        $zero = ['net_total' => '0.0000', 'tax_total' => '0.0000', 'sale_plus_tax' => '0.0000'];

        $this->assertTrue(SalesAmounts::isEmptyLine('0.0000', $zero, []));
        $this->assertFalse(SalesAmounts::isEmptyLine('1.0000', $zero, []));
        $this->assertFalse(SalesAmounts::isEmptyLine('0.0000', ['net_total' => '5.0000'] + $zero, []));
    }

    public function test_credit_invoice_total_must_equal_subtotal_minus_discount_plus_tax(): void
    {
        $ok = $this->row();
        CreditAmounts::check($ok, ['subtotal' => '100.0000', 'discount_total' => '10.0000', 'tax_total' => '11.7000', 'total' => '101.7000']);
        $this->assertFalse($ok->failed());

        $bad = $this->row();
        CreditAmounts::check($bad, ['subtotal' => '100.0000', 'discount_total' => '10.0000', 'tax_total' => '11.7000', 'total' => '120.0000']);
        $this->assertTrue($bad->failed());
        $this->assertContains('amount_mismatch', $this->codes());
    }

    public function test_credit_invoice_total_must_be_positive_and_amounts_not_negative(): void
    {
        $row = $this->row();
        CreditAmounts::check($row, ['subtotal' => '0.0000', 'discount_total' => '0.0000', 'tax_total' => '-1.0000', 'total' => '0.0000']);

        $this->assertContains('non_positive_total', $this->codes());
        $this->assertContains('negative_amount', $this->codes());
    }
}
