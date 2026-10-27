<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Enums\ImportStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\Sales\NetCorrection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesFixtures;
use Tests\TestCase;

/** Conciliación: cada diferencia se provoca a propósito en datos cuadrados y se comprueba que se detecta y se localiza. */
class ConciliationTest extends TestCase
{
    use BuildsSalesFixtures, RefreshDatabase;

    private Company $alfa;

    private Company $beta;

    private Branch $centro;

    private Branch $norte;

    private Operator $operator;

    private Seller $ana;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['name' => 'Alfa']);
        $this->beta = Company::factory()->create(['name' => 'Beta']);
        $this->centro = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Centro']);
        $this->norte = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Norte']);
        $this->operator = $this->operator($this->alfa, 'Omar');
        $this->ana = $this->seller($this->alfa, 'Ana');
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->manager->companies()->sync([$this->alfa->id]);
    }

    /** @return array<string, mixed> */
    private function report(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->manager, 'sanctum')->getJson('/api/v1/reports/conciliation?'.http_build_query($query + ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function issues(string $code, int $page = 1): array
    {
        return $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/conciliation/issues?from=2026-10-01&to=2026-10-31&code=$code&page=$page")->assertOk()->json('data');
    }

    private function integrity(array $report, string $code): array
    {
        return collect($report['integrity'])->firstWhere('code', $code);
    }

    private function cleanSales(): array
    {
        $a = $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0], ['net' => 400.0]]);
        $b = $this->sale($this->ana, $this->operator, $this->norte, '2026-10-20', 'C2', [['net' => 500.0]]);

        return [$a, $b];
    }

    public function test_clean_data_reconciles_everywhere(): void
    {
        $this->cleanSales();

        $report = $this->report();

        $this->assertEquals(1500.0, $report['consolidated']['net_total']);
        $this->assertEquals(150.0, $report['consolidated']['tax_total']);
        $this->assertEquals(1650.0, $report['consolidated']['grand_total']);
        $this->assertSame(2, $report['consolidated']['documents']);
        $this->assertSame(3, $report['consolidated']['lines']);
        $this->assertSame(['company', 'branch', 'seller', 'operator'], array_column($report['breakdowns'], 'dimension'));
        foreach ($report['breakdowns'] as $b) {
            $this->assertTrue($b['matches'], $b['dimension']);
            $this->assertEquals(1500.0, $b['net_total']);
            $this->assertSame(2, $b['documents']);
        }
        $this->assertSame([1, 2, 1, 1], array_column($report['breakdowns'], 'rows'));
        foreach ($report['integrity'] as $check) {
            $this->assertTrue($check['ok'], $check['code']);
        }
        $this->assertSame(0, $report['problems']);
        $this->assertSame(['linked' => 0, 'mismatched' => 0, 'unlinked' => 0], $report['credit']);
    }

    public function test_a_document_whose_header_disagrees_with_its_lines_is_found_and_described(): void
    {
        [$a] = $this->cleanSales();
        DB::table('sales_documents')->where('id', $a)->update(['lines_count' => 5]);

        $report = $this->report();

        $this->assertSame(1, $this->integrity($report, 'lines_count_mismatch')['count']);
        $this->assertSame(1, $report['problems']);
        $row = $this->issues('lines_count_mismatch')['rows'][0];
        $this->assertSame($a, $row['id']);
        $this->assertSame('El documento declara 5 líneas y tiene 2.', $row['detail']);
        $this->assertSame('Centro', $row['branch']);
    }

    public function test_a_document_without_lines_is_found(): void
    {
        [$a] = $this->cleanSales();
        DB::table('sales_lines')->where('sales_document_id', $a)->delete();

        $report = $this->report();

        $this->assertSame(1, $this->integrity($report, 'documents_without_lines')['count']);
        $this->assertSame(0, $this->integrity($report, 'lines_count_mismatch')['count'], 'Sin líneas no se cuenta también como diferencia de cantidad.');
        $this->assertSame('El documento no tiene ninguna línea.', $this->issues('documents_without_lines')['rows'][0]['detail']);
    }

    public function test_lines_that_belong_to_another_branch_date_or_company_are_found(): void
    {
        [$a, $b] = $this->cleanSales();
        $first = DB::table('sales_lines')->where('sales_document_id', $a)->orderBy('id')->first();
        DB::table('sales_lines')->where('id', $first->id)->update(['branch_id' => $this->norte->id]);
        DB::table('sales_lines')->where('sales_document_id', $b)->update(['issued_on' => '2026-10-21']);
        $this->operator($this->beta, 'Olga');
        $other = DB::table('sales_lines')->where('sales_document_id', $a)->orderByDesc('id')->first();
        DB::table('sales_lines')->where('id', $other->id)->update(['company_id' => $this->beta->id]);

        $report = $this->report();

        $this->assertSame(1, $this->integrity($report, 'line_branch_mismatch')['count']);
        $this->assertSame(1, $this->integrity($report, 'line_date_mismatch')['count']);
        $this->assertSame(1, $this->integrity($report, 'line_company_mismatch')['count']);
        $this->assertSame('1 línea(s) pertenecen a otra sucursal que el documento.', $this->issues('line_branch_mismatch')['rows'][0]['detail']);
        $this->assertSame('1 línea(s) tienen una fecha distinta a la del documento.', $this->issues('line_date_mismatch')['rows'][0]['detail']);
    }

    public function test_a_line_whose_total_is_not_net_plus_tax_is_found(): void
    {
        [$a] = $this->cleanSales();
        $line = DB::table('sales_lines')->where('sales_document_id', $a)->first();
        DB::table('sales_lines')->where('id', $line->id)->update(['grand_total' => $line->grand_total + 5]);
        DB::table('sales_lines')->where('sales_document_id', '<>', $a)->update(['grand_total' => DB::raw('grand_total + 0.005')]);   // dentro de la tolerancia

        $report = $this->report();

        $this->assertSame(1, $this->integrity($report, 'line_total_mismatch')['count']);
    }

    public function test_the_issues_list_is_paginated_and_validates_the_code(): void
    {
        for ($i = 0; $i < 55; $i++) {
            $id = $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 10.0]]);
            DB::table('sales_documents')->where('id', $id)->update(['lines_count' => 9]);
        }

        $this->assertCount(50, $this->issues('lines_count_mismatch')['rows']);
        $this->assertCount(5, $this->issues('lines_count_mismatch', 2)['rows']);
        $this->assertSame(55, $this->issues('lines_count_mismatch')['total']);
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/conciliation/issues?from=2026-10-01&to=2026-10-31&code=inventado')->assertStatus(422)->assertJsonValidationErrors('code');
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/conciliation/issues?from=2026-10-01&to=2026-10-31')->assertStatus(422);
    }

    private function importedBatch(array $over = [], array $summary = []): ImportBatch
    {
        return ImportBatch::factory()->create($over + [
            'status' => ImportStatus::Imported, 'documents_new' => 2, 'documents_duplicate' => 0, 'finished_at' => now(),
            'summary' => $summary + ['date_from' => '2026-10-01', 'date_to' => '2026-10-31', 'totals' => ['net_total' => '1500.00'], 'companies' => [['id' => $this->alfa->id, 'documents' => 2, 'lines' => 3, 'net_total' => '1500.00']]],
        ]);
    }

    public function test_an_import_whose_file_matches_what_was_stored_reconciles(): void
    {
        $batch = $this->importedBatch();
        $this->batchId = $batch->id;
        $this->cleanSales();

        $imports = $this->report()['imports'];

        $this->assertSame(1, $imports['matched']);
        $this->assertSame(0, $imports['differing']);
        $this->assertSame($batch->id, $imports['batches'][0]['id']);
        $this->assertEquals(1500.0, $imports['batches'][0]['registered_net']);
        $this->assertSame(0, $this->report()['problems']);
    }

    public function test_an_import_that_lost_documents_or_money_is_flagged(): void
    {
        $batch = $this->importedBatch(['documents_new' => 3], ['totals' => ['net_total' => '1800.00']]);
        $this->batchId = $batch->id;
        $this->cleanSales();

        $report = $this->report();

        $this->assertSame(1, $report['imports']['differing']);
        $this->assertFalse($report['imports']['batches'][0]['matches']);
        $this->assertSame(3, $report['imports']['batches'][0]['declared_documents']);
        $this->assertSame(2, $report['imports']['batches'][0]['registered_documents']);
        $this->assertEquals(1800.0, $report['imports']['batches'][0]['declared_net']);
        $this->assertSame(1, $report['problems']);
    }

    public function test_with_duplicates_only_the_document_count_is_compared_not_the_money(): void
    {
        // El archivo traía además un documento ya cargado antes: su venta está en el total del archivo pero no en este lote.
        $batch = $this->importedBatch(['documents_duplicate' => 1], ['totals' => ['net_total' => '2300.00']]);
        $this->batchId = $batch->id;
        $this->cleanSales();

        $row = $this->report()['imports']['batches'][0];

        $this->assertFalse($row['net_comparable']);
        $this->assertTrue($row['matches']);
        $this->assertSame(1, $this->report()['imports']['not_comparable']);
    }

    public function test_a_batch_that_never_declared_its_net_sales_is_not_called_different(): void
    {
        $batch = $this->importedBatch(summary: ['totals' => []]);
        $this->batchId = $batch->id;
        $this->cleanSales();

        $report = $this->report();

        $this->assertFalse($report['imports']['batches'][0]['net_comparable']);
        $this->assertTrue($report['imports']['batches'][0]['matches']);
        $this->assertSame(0, $report['problems']);
    }

    public function test_imports_outside_the_period_or_the_scope_are_not_evaluated(): void
    {
        $this->importedBatch([], ['date_from' => '2026-01-01', 'date_to' => '2026-01-31']);
        $this->importedBatch([], ['companies' => [['id' => $this->alfa->id], ['id' => $this->beta->id]]]);
        $this->cleanSales();

        $imports = $this->report()['imports'];

        $this->assertSame([], $imports['batches']);
        $this->assertSame(1, $imports['out_of_scope']);
        $this->assertCount(1, $this->report(as: User::factory()->role(Role::ADMINISTRADOR)->create())['imports']['batches'], 'Administración sí ve el lote de dos compañías.');
    }

    private function creditInvoice(int $documentId, array $over = []): int
    {
        $sale = DB::table('sales_documents')->where('id', $documentId)->first();
        $lines = DB::table('sales_lines')->where('sales_document_id', $documentId)->selectRaw('sum(net_total) as net, sum(tax_total) as tax')->first();
        $batch = DB::table('import_batches')->value('id');

        return DB::table('credit_invoices')->insertGetId($over + [
            'company_id' => $sale->company_id, 'branch_id' => $sale->branch_id, 'series' => $sale->series, 'number' => $sale->number, 'issued_on' => $sale->issued_on,
            'customer_code' => $sale->customer_code, 'customer_name' => $sale->customer_name, 'seller_id' => $sale->seller_id, 'operator_id' => $sale->operator_id,
            'subtotal' => $lines->net, 'discount_total' => 0, 'tax_total' => $lines->tax, 'total' => $lines->net + $lines->tax, 'sales_document_id' => $documentId,
            'import_batch_id' => $batch, 'content_hash' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_credit_invoices_are_compared_with_their_sales_documents(): void
    {
        [$a, $b] = $this->cleanSales();
        $c = $this->sale($this->ana, $this->operator, $this->centro, '2026-10-22', 'C3', [['net' => 100.0]]);
        $d = $this->sale($this->ana, $this->operator, $this->centro, '2026-10-23', 'C4', [['net' => 200.0]]);
        $this->creditInvoice($a);                                              // cuadra
        $this->creditInvoice($b, ['subtotal' => 450.0]);                       // neto distinto
        $this->creditInvoice($c, ['customer_code' => 'OTRO']);                 // otro cliente
        $this->creditInvoice($d, ['sales_document_id' => null, 'number' => '999']);   // sin enlazar

        $report = $this->report();

        $this->assertSame(['linked' => 3, 'mismatched' => 2, 'unlinked' => 1], $report['credit']);
        $this->assertSame(1, $report['problems']);
        $issues = $this->issues('credit_invoice_mismatch');
        $this->assertSame(2, $issues['total']);
        $details = implode(' | ', array_column($issues['rows'], 'detail'));
        $this->assertStringContainsString('neto 450.00 en crédito y 500.00 en ventas', $details);
        $this->assertStringContainsString('cliente OTRO en crédito y C3 en ventas', $details);
    }

    public function test_a_credit_discount_is_subtracted_before_comparing_the_net(): void
    {
        [$a] = $this->cleanSales();
        $this->creditInvoice($a, ['subtotal' => 1100.0, 'discount_total' => 100.0]);   // 1100 − 100 = 1000 = neto de la venta

        $this->assertSame(0, $this->report()['credit']['mismatched']);
    }

    public function test_the_branch_filter_narrows_everything(): void
    {
        [$a, $b] = $this->cleanSales();
        DB::table('sales_documents')->where('id', $b)->update(['lines_count' => 7]);

        $norte = $this->report(['branch_id' => $this->norte->id]);
        $centro = $this->report(['branch_id' => $this->centro->id]);

        $this->assertEquals(500.0, $norte['consolidated']['net_total']);
        $this->assertSame(1, $this->integrity($norte, 'lines_count_mismatch')['count']);
        $this->assertEquals(1000.0, $centro['consolidated']['net_total']);
        $this->assertSame(0, $this->integrity($centro, 'lines_count_mismatch')['count']);
    }

    public function test_a_manager_never_sees_another_company(): void
    {
        $this->cleanSales();
        $branchBeta = Branch::factory()->create(['company_id' => $this->beta->id]);
        $olga = $this->operator($this->beta, 'Olga');
        $beto = $this->seller($this->beta, 'Beto');
        $bad = $this->sale($beto, $olga, $branchBeta, '2026-10-05', 'C1', [['net' => 9999.0]]);
        DB::table('sales_documents')->where('id', $bad)->update(['lines_count' => 9]);

        $report = $this->report();

        $this->assertEquals(1500.0, $report['consolidated']['net_total']);
        $this->assertSame(0, $report['problems'], 'Los problemas de otra compañía no se ven.');
        $this->assertSame([], $this->issues('lines_count_mismatch')['rows']);
        $this->assertEquals(0.0, $this->report(['company_id' => $this->beta->id])['consolidated']['net_total']);
    }

    public function test_sellers_cannot_open_the_conciliation_and_everything_is_audited(): void
    {
        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $seller->companies()->sync([$this->alfa->id]);
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/reports/conciliation?from=2026-10-01&to=2026-10-31')->assertForbidden();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/reports/conciliation/issues?from=2026-10-01&to=2026-10-31&code=lines_count_mismatch')->assertForbidden();

        $this->report(['company_id' => $this->alfa->id]);
        $this->issues('documents_without_lines');

        $this->assertSame(['conciliation', 'conciliation_issues'], AuditLog::where('event', 'report.viewed')->orderBy('id')->get()->map(fn ($l) => $l->new_values['report'])->all());
    }

    public function test_invalid_queries_are_rejected(): void
    {
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/conciliation')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/conciliation?from=2026-10-31&to=2026-10-01')->assertStatus(422);
    }

    /** @param  array<string, mixed>  $o */
    private function erpRow(string $seller, array $o = []): void
    {
        DB::table('seller_sales_summaries')->insert([
            'company_id' => $this->alfa->id, 'import_batch_id' => ImportBatch::factory()->create()->id, 'period_from' => '2026-10-01', 'cutoff_on' => '2026-10-31',
            'seller_name' => $seller, 'normalized_name' => Seller::normalize($seller), 'gross_sale' => 0, 'discount' => 0, 'sale' => 0, 'returns' => 0, 'net_sale' => 0,
            'tax' => 0, 'withholding' => 0, 'total' => 0, 'invoices_count' => 0, 'created_at' => now(), 'updated_at' => now(), ...$o,
        ]);
    }

    public function test_the_origin_summary_is_compared_with_the_corrected_and_the_imported_net_per_seller(): void
    {
        // Ana: una línea entera (400) y una por equivalencia que el reporte dejó en 25 y vale 100; una factura anulada de 300 con su venta cargada.
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 400.0], ['net' => 25.0, 'qty' => 0.25, 'price' => 100.0]]);
        $void = $this->sale($this->ana, $this->operator, $this->norte, '2026-10-06', 'C2', [['net' => 300.0]]);
        DB::table('sales_document_voids')->insert(['company_id' => $this->alfa->id, 'document' => 'void', 'sales_document_id' => $void, 'import_batch_id' => ImportBatch::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
        app(NetCorrection::class)->run();
        $this->erpRow('Ana', ['net_sale' => 500, 'invoices_count' => 3]);
        $this->erpRow('Beto', ['net_sale' => 80, 'invoices_count' => 1]);   // el origen trae un vendedor que el sistema no conoce

        $cut = $this->report()['origin'][0];

        $this->assertSame(['Alfa', '2026-10-01', '2026-10-31'], [$cut['company']['name'], $cut['period_from'], $cut['cutoff']]);
        $ana = collect($cut['rows'])->firstWhere('seller', 'Ana');
        $this->assertEquals([500, 500, 725, 0, 1, 1, 'ok'], [$ana['erp_net'], $ana['system_net'], $ana['imported_net'], $ana['difference'], $ana['system_documents'], $ana['voided_documents'], $ana['status']]);
        $beto = collect($cut['rows'])->firstWhere('seller', 'Beto');
        $this->assertEquals([80, 0, 80, 'differs'], [$beto['erp_net'], $beto['system_net'], $beto['difference'], $beto['status']]);
        $this->assertEquals([580, 500, 80, 4], [$cut['totals']['erp_net'], $cut['totals']['system_net'], $cut['totals']['difference'], $cut['totals']['erp_invoices']]);
        $this->assertGreaterThan(0, $this->report()['problems']);
    }

    public function test_no_origin_section_without_a_loaded_summary_and_a_small_gap_is_only_near(): void
    {
        $this->assertSame([], $this->report()['origin']);

        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 1000.0]]);
        $this->erpRow('Ana', ['net_sale' => 1005, 'invoices_count' => 1]);

        $this->assertSame('near', $this->report()['origin'][0]['rows'][0]['status']);
    }
}
