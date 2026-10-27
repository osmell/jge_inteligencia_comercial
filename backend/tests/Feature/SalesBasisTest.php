<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use App\Models\User;
use App\Services\Sales\NetCorrection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Cifras corregidas (por omisión) y cifras tal como se importaron (`basis=imported`). */
class SalesBasisTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->company = Company::factory()->create(['code' => 'A']);
        $branch = Branch::factory()->create(['company_id' => $this->company->id]);
        $seller = Seller::create(['company_id' => $this->company->id, 'name' => 'Ana', 'normalized_name' => 'ana']);

        // Factura 1: una línea entera (200) y una por equivalencia: 1/4 de galón a 112.50 que el reporte dejó en 28.125.
        $this->document($branch, $seller, '1', [[2, 100, 200], [0.25, 112.5, 28.125]]);
        // Factura 2: anulada según el detalle de facturación; su venta no debe contarse.
        $void = $this->document($branch, $seller, '2', [[1, 500, 500]]);
        DB::table('sales_document_voids')->insert([
            'company_id' => $this->company->id, 'document' => '2', 'sales_document_id' => $void->id,
            'import_batch_id' => ImportBatch::factory()->create()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(NetCorrection::class)->run();
    }

    /** @param list<array{0: float, 1: float, 2: float}> $lines unidades, precio unitario, neto */
    private function document(Branch $branch, Seller $seller, string $number, array $lines): SalesDocument
    {
        $doc = SalesDocument::factory()->create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id, 'series' => 'S', 'number' => $number, 'issued_on' => '2026-10-10',
            'customer_code' => 'C1', 'customer_name' => 'Cliente', 'seller_id' => $seller->id, 'lines_count' => count($lines),
        ]);
        foreach ($lines as $i => [$units, $price, $net]) {
            SalesLine::create([
                'sales_document_id' => $doc->id, 'company_id' => $this->company->id, 'branch_id' => $branch->id, 'issued_on' => '2026-10-10', 'line_number' => $i + 1,
                'product_code' => "P$i", 'product_name' => "Producto $i", 'category_name' => 'Cat', 'units' => $units, 'unit_cost' => 0, 'line_cost_total' => 0,
                'unit_price' => $price, 'net_total' => $net, 'tax_total' => $net * 0.1, 'sale_plus_tax' => $net * 1.1, 'grand_total' => $net * 1.1,
            ]);
        }

        return $doc;
    }

    private function summary(string $extra = ''): array
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/summary?from=2026-10-01&to=2026-10-31'.$extra)->assertOk()->json('data.current');
    }

    public function test_corrected_figures_fix_the_equivalence_line_and_leave_out_the_voided_invoice(): void
    {
        $data = $this->summary();

        $this->assertSame('312.50', $data['net_total']);   // 200 + 112.50; la anulada (500) no cuenta
        $this->assertSame('31.25', $data['tax_total']);    // 10 % de cada neto corregido
        $this->assertSame('343.75', $data['grand_total']);
        $this->assertSame(1, $data['documents']);
    }

    public function test_the_imported_basis_shows_the_figures_exactly_as_the_file_brought_them(): void
    {
        $data = $this->summary('&basis=imported');

        $this->assertSame('728.13', $data['net_total']);   // 200 + 28.125 + 500
        $this->assertSame(2, $data['documents']);
    }

    public function test_lines_are_never_modified_by_the_correction(): void
    {
        $this->assertEquals(28.125, SalesLine::where('units', 0.25)->sole()->net_total);
    }
}
