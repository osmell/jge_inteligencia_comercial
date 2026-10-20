<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\Credit\CreditInvoicesImportType;
use App\Imports\ImportRunner;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Xlsx;
use Tests\TestCase;

class CreditInvoicesImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Fecha', 'Descuento', 'Subtotal', 'Impuesto', 'Total', 'Documento', 'Serie', 'Codigo Cliente', 'Nombre Cliente', 'Nombre Vendedor', 'Nombre Operador', 'Sucursal', 'Compañia'];

    private User $admin;

    private Company $alfa;

    private Branch $central;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['code' => 'ALFA', 'name' => 'Compañía Alfa']);
        $this->central = Branch::factory()->create(['company_id' => $this->alfa->id, 'code' => 'CEN', 'name' => 'Central']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    /** Factura de 1 000 con 10 % de descuento y 15 % de IVA: (1000 − 100) × 1.15 = 1035. */
    private function line(array $o = []): array
    {
        $d = [
            'Fecha' => '2026-10-01', 'Descuento' => 100, 'Subtotal' => 1000, 'Impuesto' => 135, 'Total' => 1035, 'Documento' => 3088, 'Serie' => 'FC-D', 'Codigo Cliente' => 3892,
            'Nombre Cliente' => 'LYDIA LUDIC', 'Nombre Vendedor' => 'FERRETERIA JGE', 'Nombre Operador' => 'ANGIE', 'Sucursal' => 'CEN', 'Compañia' => 'ALFA', ...$o,
        ];

        return array_map(fn ($h) => $d[$h], self::HEADER);
    }

    private function validate(array $lines): ImportBatch
    {
        $path = Xlsx::make([self::HEADER, ...$lines]);
        $stored = 'credit/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));
        $batch = ImportBatch::factory()->create(['type' => 'credit_invoices', 'status' => ImportStatus::Pending, 'stored_path' => $stored, 'file_hash' => hash_file('sha256', $path), 'user_id' => $this->admin->id]);
        app(ImportRunner::class)->validate($batch);

        return $batch->fresh();
    }

    private function imported(array $lines): ImportBatch
    {
        $batch = $this->validate($lines);
        $this->assertSame(ImportStatus::Validated, $batch->status, json_encode($batch->errors()->pluck('message')));
        app(ImportRunner::class)->import($batch);

        return $batch->fresh();
    }

    public function test_invoices_are_imported_with_an_opening_movement_and_their_people(): void
    {
        $batch = $this->imported([$this->line(), $this->line(['Documento' => 3089, 'Descuento' => 0, 'Subtotal' => 200, 'Impuesto' => 30, 'Total' => 230, 'Nombre Vendedor' => 'IRIS'])]);

        $this->assertSame(ImportStatus::Imported, $batch->status);
        $invoice = CreditInvoice::where('number', '3088')->sole();
        $this->assertSame(['FC-D', '2026-10-01', '3892', 'LYDIA LUDIC', '1000.0000', '100.0000', '135.0000', '1035.0000'], [
            $invoice->series, $invoice->issued_on->toDateString(), $invoice->customer_code, $invoice->customer_name, $invoice->subtotal, $invoice->discount_total, $invoice->tax_total, $invoice->total,
        ]);
        $this->assertSame($this->central->id, $invoice->branch_id);
        $this->assertSame('FERRETERIA JGE', $invoice->seller->name);
        $this->assertSame(1, Operator::where('name', 'ANGIE')->count());
        $this->assertSame(2, Seller::count());

        $movement = CreditMovement::where('credit_invoice_id', $invoice->id)->sole();
        $this->assertSame([CreditMovement::INVOICE, '1035.0000', '2026-10-01', $this->admin->id], [$movement->type, $movement->amount, $movement->occurred_on->toDateString(), $movement->user_id]);
        $this->assertSame(2, $batch->documents_total);
        $this->assertSame('1265.0000', $batch->summary['credit']['totals']['total']);
        $this->assertSame(['FERRETERIA JGE', 'IRIS'], $batch->summary['credit']['new_sellers']);
    }

    public function test_the_discount_column_may_be_empty_and_means_zero(): void
    {
        $this->imported([$this->line(['Descuento' => null, 'Subtotal' => 1000, 'Impuesto' => 150, 'Total' => 1150])]);

        $this->assertSame('0.0000', CreditInvoice::sole()->discount_total);
    }

    public function test_total_must_equal_subtotal_minus_discount_plus_tax(): void
    {
        $batch = $this->validate([$this->line(['Total' => 1000])]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $e = $batch->errors()->where('code', 'amount_mismatch')->sole();
        $this->assertStringContainsString('(1000.00) no coincide con «Subtotal» − «Descuento» + «Impuesto» (1035.00)', $e->message);
    }

    public function test_negative_amounts_and_zero_totals_are_rejected(): void
    {
        $batch = $this->validate([
            $this->line(['Impuesto' => -135, 'Total' => 765]),
            $this->line(['Documento' => 1, 'Subtotal' => 0, 'Descuento' => 0, 'Impuesto' => 0, 'Total' => 0]),
        ]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertNotNull($batch->errors()->where('code', 'negative_amount')->first());
        $this->assertNotNull($batch->errors()->where('code', 'non_positive_total')->first());
    }

    public function test_unknown_company_branch_and_missing_values_are_reported(): void
    {
        $batch = $this->validate([$this->line(['Compañia' => 'XX']), $this->line(['Documento' => 2, 'Sucursal' => 'NOPE']), $this->line(['Documento' => 3, 'Nombre Vendedor' => null, 'Serie' => null])]);

        $codes = $batch->errors()->pluck('code')->all();
        $this->assertContains('unknown_company', $codes);
        $this->assertContains('unknown_branch', $codes);
        $this->assertContains('required', $codes);
    }

    public function test_repeated_invoices_in_the_file_warn_or_conflict(): void
    {
        $same = $this->validate([$this->line(), $this->line()]);
        $this->assertSame(ImportStatus::Validated, $same->status);
        $this->assertNotNull($same->errors()->where('code', 'duplicate_row')->first());

        $conflict = $this->validate([$this->line(), $this->line(['Nombre Cliente' => 'OTRO'])]);
        $this->assertSame(ImportStatus::Rejected, $conflict->status);
        $this->assertNotNull($conflict->errors()->where('code', 'inconsistent_group')->first());
    }

    public function test_an_invoice_already_imported_is_skipped_and_a_changed_one_is_a_conflict(): void
    {
        $this->imported([$this->line()]);

        $again = $this->imported([$this->line(), $this->line(['Documento' => 3089])]);
        $this->assertSame(1, $again->documents_new);
        $this->assertSame(1, $again->documents_duplicate);
        $this->assertSame(2, CreditInvoice::count());
        $this->assertSame(2, CreditMovement::count());

        $changed = $this->validate([$this->line(['Descuento' => 0, 'Subtotal' => 1035, 'Impuesto' => 0, 'Total' => 1035])]);
        $this->assertSame(ImportStatus::Rejected, $changed->status);
        $this->assertStringContainsString('ya existe con contenido distinto', $changed->errors()->where('code', 'document_conflict')->sole()->message);
    }

    public function test_it_links_to_the_sales_invoice_and_warns_when_amounts_differ(): void
    {
        $sale = SalesDocument::factory()->create(['company_id' => $this->alfa->id, 'branch_id' => $this->central->id, 'series' => 'FC-D', 'number' => '3088', 'customer_code' => '3892']);
        DB::table('sales_lines')->insert([
            'sales_document_id' => $sale->id, 'company_id' => $this->alfa->id, 'branch_id' => $this->central->id, 'issued_on' => '2026-10-01', 'line_number' => 1, 'product_code' => '6001', 'product_name' => 'X',
            'category_name' => 'C', 'units' => 1, 'line_cost_total' => 1, 'unit_cost' => 1, 'unit_price' => 900, 'net_total' => 900, 'tax_total' => 135, 'sale_plus_tax' => 1035, 'grand_total' => 1035,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $ok = $this->imported([$this->line()]);
        $this->assertSame(1, $ok->summary['credit']['linked_to_sales']);
        $this->assertSame(0, $ok->summary['credit']['mismatches']);
        $this->assertSame($sale->id, CreditInvoice::sole()->sales_document_id);

        $batch = $this->validate([$this->line(['Documento' => 3088, 'Subtotal' => 2000, 'Descuento' => 0, 'Impuesto' => 300, 'Total' => 2300, 'Fecha' => '2026-10-02'])]);
        $this->assertNotNull($batch->errors()->where('code', 'document_conflict')->first());
    }

    public function test_sales_mismatch_and_customer_mismatch_are_warnings_only(): void
    {
        $sale = SalesDocument::factory()->create(['company_id' => $this->alfa->id, 'branch_id' => $this->central->id, 'series' => 'FC-D', 'number' => '3088', 'customer_code' => '999']);
        DB::table('sales_lines')->insert([
            'sales_document_id' => $sale->id, 'company_id' => $this->alfa->id, 'branch_id' => $this->central->id, 'issued_on' => '2026-10-01', 'line_number' => 1, 'product_code' => '6001', 'product_name' => 'X',
            'category_name' => 'C', 'units' => 1, 'line_cost_total' => 1, 'unit_cost' => 1, 'unit_price' => 500, 'net_total' => 500, 'tax_total' => 75, 'sale_plus_tax' => 575, 'grand_total' => 575,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $batch = $this->validate([$this->line()]);

        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertNotNull($batch->errors()->where('code', 'sales_mismatch')->where('severity', 'warning')->first());
        $this->assertNotNull($batch->errors()->where('code', 'sales_customer_mismatch')->first());
        $this->assertSame(2, $batch->summary['credit']['mismatches']);
    }

    public function test_a_later_sales_import_links_the_pending_invoices(): void
    {
        $this->imported([$this->line()]);
        $this->assertNull(CreditInvoice::sole()->sales_document_id);
        $this->assertSame(1, $this->importedWithoutSales());

        $sale = SalesDocument::factory()->create(['company_id' => $this->alfa->id, 'branch_id' => $this->central->id, 'series' => 'FC-D', 'number' => '3088']);

        $this->assertSame(1, CreditInvoicesImportType::linkPendingToSales([$this->alfa->id]));
        $this->assertSame($sale->id, CreditInvoice::sole()->sales_document_id);
        $this->assertSame(0, CreditInvoicesImportType::linkPendingToSales());
    }

    private function importedWithoutSales(): int
    {
        return CreditInvoice::whereNull('sales_document_id')->count();
    }

    public function test_credit_invoices_are_immutable(): void
    {
        $this->imported([$this->line()]);

        $this->expectException(\LogicException::class);
        CreditInvoice::sole()->update(['total' => 1]);
    }

    public function test_the_template_lists_the_columns(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/imports/types')->assertOk()->assertJsonFragment(['key' => 'credit_invoices', 'label' => 'Facturas de crédito']);
        $this->get('/api/v1/admin/imports/templates/credit_invoices')->assertOk();
    }
}
