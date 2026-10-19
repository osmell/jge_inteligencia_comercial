<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class ImportSchemaTest extends TestCase
{
    use RefreshDatabase;

    private function line(SalesDocument $doc, int $n = 1, array $o = []): SalesLine
    {
        return SalesLine::create([
            'sales_document_id' => $doc->id, 'company_id' => $doc->company_id, 'branch_id' => $doc->branch_id, 'issued_on' => $doc->issued_on,
            'line_number' => $n, 'product_code' => '000123', 'product_name' => 'Producto', 'category_name' => 'Categoría',
            'units' => 2, 'unit_cost' => '10.1234', 'line_cost_total' => '20.2468', 'unit_price' => 15, 'normal_price' => null,
            'net_total' => 30, 'tax_total' => '3.6', 'sale_plus_tax' => '33.6', 'grand_total' => '33.6', ...$o,
        ]);
    }

    public function test_document_identity_is_company_series_and_number(): void
    {
        $doc = SalesDocument::factory()->create(['series' => 'A', 'number' => '0001']);
        $otherCompany = Branch::factory()->create();

        // Mismo número y serie en otra compañía: permitido.
        SalesDocument::factory()->create(['company_id' => $otherCompany->company_id, 'branch_id' => $otherCompany->id, 'series' => 'A', 'number' => '0001']);
        // Misma serie y número en otra serie: permitido.
        SalesDocument::factory()->create(['company_id' => $doc->company_id, 'branch_id' => $doc->branch_id, 'series' => 'B', 'number' => '0001']);

        $this->expectException(QueryException::class);
        SalesDocument::factory()->create(['company_id' => $doc->company_id, 'branch_id' => $doc->branch_id, 'series' => 'A', 'number' => '0001']);
    }

    public function test_codes_keep_leading_zeros_and_amounts_keep_four_decimals(): void
    {
        $doc = SalesDocument::factory()->create(['number' => '000045']);
        $line = $this->line($doc);

        $this->assertSame('000045', $doc->fresh()->number);
        $this->assertSame('000123', $line->fresh()->product_code);
        $this->assertSame('10.1234', $line->fresh()->unit_cost);
        $this->assertSame('3.6000', $line->fresh()->tax_total);
        $this->assertNull($line->fresh()->normal_price);
    }

    public function test_line_numbers_are_unique_within_a_document(): void
    {
        $doc = SalesDocument::factory()->create();
        $this->line($doc, 1);
        $this->line($doc, 2);

        $this->expectException(QueryException::class);
        $this->line($doc, 1);
    }

    public function test_sales_records_cannot_be_modified_or_deleted(): void
    {
        $doc = SalesDocument::factory()->create();
        $line = $this->line($doc);

        foreach ([fn () => $doc->update(['customer_name' => 'x']), fn () => $doc->delete(), fn () => $line->update(['units' => 9]), fn () => $line->delete()] as $action) {
            try {
                $action();
                $this->fail('Se esperaba LogicException');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame('2.0000', $line->fresh()->units);
    }

    public function test_same_file_can_only_be_imported_once_per_type(): void
    {
        $hash = hash('sha256', 'archivo');
        ImportBatch::factory()->status(ImportStatus::Imported)->create(['file_hash' => $hash]);
        // Rechazado o validado con el mismo hash: permitido (aún no se importó).
        ImportBatch::factory()->status(ImportStatus::Rejected)->create(['file_hash' => $hash]);
        ImportBatch::factory()->status(ImportStatus::Validated)->create(['file_hash' => $hash]);
        // Otro tipo con el mismo hash: permitido.
        ImportBatch::factory()->status(ImportStatus::Imported)->create(['file_hash' => $hash, 'type' => 'clients']);

        $this->expectException(QueryException::class);
        ImportBatch::factory()->status(ImportStatus::Imported)->create(['file_hash' => $hash]);
    }

    public function test_batch_status_is_cast_and_errors_are_deleted_with_the_batch(): void
    {
        $batch = ImportBatch::factory()->create(['summary' => ['preview' => [1, 2]]]);
        $batch->errors()->create(['row_number' => 3, 'column' => 'Total Neto', 'code' => 'invalid_number', 'message' => 'No es un número.', 'value' => 'abc']);

        $this->assertSame(ImportStatus::Validated, $batch->fresh()->status);
        $this->assertSame([1, 2], $batch->fresh()->summary['preview']);
        $this->assertSame('error', $batch->errors()->first()->severity);

        $batch->errors()->delete();
        $this->assertSame(0, $batch->errors()->count());
    }

    public function test_seller_and_operator_names_are_unique_per_company_ignoring_case(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        $this->assertSame('maría lópez', Seller::normalize('  MARÍA  LÓPEZ '));
        Seller::create(['company_id' => $a->id, 'name' => 'María López', 'normalized_name' => Seller::normalize('María López')]);
        Seller::create(['company_id' => $b->id, 'name' => 'María López', 'normalized_name' => Seller::normalize('María López')]);
        Operator::create(['company_id' => $a->id, 'name' => 'Caja 1', 'normalized_name' => Operator::normalize('Caja 1')]);

        $this->expectException(QueryException::class);
        Operator::create(['company_id' => $a->id, 'name' => 'CAJA   1', 'normalized_name' => Operator::normalize('CAJA   1')]);
    }
}
