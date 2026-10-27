<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Models\BillingDetailLine;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Role;
use App\Models\SalesDocumentVoid;
use App\Models\Seller;
use App\Models\SellerSalesSummary;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Xlsx;
use Tests\TestCase;

class BillingImportsTest extends TestCase
{
    use RefreshDatabase;

    private const DETAIL = ['Compañía', 'Nombre del Cliente', 'Fecha', 'Serie', 'Docu.No.', 'Código de Producto', 'Nombre del Producto', 'Unidades', 'Costo', 'Precio', 'Venta Total', 'Costo Total', 'Utilidad Total'];

    private const SUMMARY = ['Compañía', 'Fecha Corte', 'Nombre del Vendedor', 'Venta Bruta', 'Descuento', 'Venta', 'Devoluciones', 'Venta Neta', 'Impuesto', 'Retención', 'Total', 'No.Fact.'];

    private User $admin;

    private Company $alfa;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['code' => 'ALFA', 'name' => 'Compañía Alfa']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    /** @param  array<string, mixed>  $o */
    private function detail(array $o = []): array
    {
        $d = [
            'Compañía' => 'ALFA', 'Nombre del Cliente' => 'ELIAS', 'Fecha' => '03/08/2026', 'Serie' => 'F$', 'Docu.No.' => 19844, 'Código de Producto' => 506,
            'Nombre del Producto' => 'LACA', 'Unidades' => 0.125, 'Costo' => 912.7862, 'Precio' => 295, 'Venta Total' => 33.1875, 'Costo Total' => 114.0983, 'Utilidad Total' => -80.9108, ...$o,
        ];

        return array_map(fn ($h) => $d[$h], self::DETAIL);
    }

    /** @param  array<string, mixed>  $o */
    private function seller(array $o = []): array
    {
        $d = [
            'Compañía' => 'ALFA', 'Fecha Corte' => '2026-08-31', 'Nombre del Vendedor' => 'IRIS CALERO', 'Venta Bruta' => 1200, 'Descuento' => 200, 'Venta' => 1000, 'Devoluciones' => null,
            'Venta Neta' => 1000, 'Impuesto' => 150, 'Retención' => 0, 'Total' => 1150, 'No.Fact.' => 3, ...$o,
        ];

        return array_map(fn ($h) => $d[$h], self::SUMMARY);
    }

    /** @param  list<string>  $header */
    private function validate(string $type, array $header, array $rows): ImportBatch
    {
        $path = Xlsx::make([$header, ...$rows]);
        $stored = 'billing/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));
        $batch = ImportBatch::factory()->create(['type' => $type, 'status' => ImportStatus::Pending, 'stored_path' => $stored, 'file_hash' => hash_file('sha256', $path), 'user_id' => $this->admin->id]);
        app(ImportRunner::class)->validate($batch);

        return $batch->fresh();
    }

    /** @param  list<string>  $header */
    private function imported(string $type, array $header, array $rows): ImportBatch
    {
        $batch = $this->validate($type, $header, $rows);
        $this->assertSame(ImportStatus::Validated, $batch->status, json_encode($batch->errors()->pluck('message')));
        app(ImportRunner::class)->import($batch);

        return $batch->fresh();
    }

    public function test_the_detail_is_stored_by_company_and_document_without_touching_sales(): void
    {
        $batch = $this->imported('billing_detail', self::DETAIL, [
            $this->detail(),
            $this->detail(['Código de Producto' => 2214, 'Unidades' => 0.1875, 'Precio' => 940]),
            $this->detail(['Docu.No.' => 19845, 'Serie' => 'FA']),
        ]);

        $this->assertSame(ImportStatus::Imported, $batch->status);
        $this->assertSame(2, $batch->documents_total);
        $this->assertSame(3, BillingDetailLine::count());
        $line = BillingDetailLine::where('document', '19844')->orderBy('line_number')->first();
        $this->assertEquals(['F$', '2026-08-03', '506', 0.125, 295, 33.1875, -80.9108], [
            $line->series, $line->issued_on->toDateString(), $line->product_code, $line->units, $line->price, $line->sale_total, $line->profit_total,
        ]);
        $this->assertSame('2026-08-03', $batch->summary['billing']['date_from']);
    }

    public function test_reloading_the_cumulative_file_skips_identical_documents_and_replaces_changed_ones(): void
    {
        $this->imported('billing_detail', self::DETAIL, [$this->detail(), $this->detail(['Docu.No.' => 19845])]);

        // Carga del día siguiente: repite lo anterior, el documento 19845 pasó a anulado (nueva fila FA) y llega uno nuevo.
        $batch = $this->imported('billing_detail', self::DETAIL, [
            $this->detail(), $this->detail(['Docu.No.' => 19845]), $this->detail(['Docu.No.' => 19845, 'Serie' => 'FA']), $this->detail(['Docu.No.' => 19846]),
        ]);

        $this->assertSame(3, $batch->documents_total);
        $this->assertSame(1, $batch->documents_duplicate);
        $this->assertSame(1, $batch->summary['billing']['documents_replaced']);
        $this->assertSame(1, BillingDetailLine::where('document', '19844')->count());
        $this->assertSame(['F$', 'FA'], BillingDetailLine::where('document', '19845')->orderBy('line_number')->pluck('series')->all());
        $this->assertSame(1, BillingDetailLine::where('document', '19846')->count());
    }

    public function test_repeated_lines_are_kept_and_reported_as_a_warning(): void
    {
        $batch = $this->imported('billing_detail', self::DETAIL, [$this->detail(), $this->detail()]);

        $this->assertSame(2, BillingDetailLine::count());
        $this->assertSame(1, $batch->warnings_total);
    }

    public function test_the_detail_needs_a_known_company(): void
    {
        $batch = $this->validate('billing_detail', self::DETAIL, [$this->detail(['Compañía' => 'NOPE'])]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertSame(['unknown_company'], $batch->errors()->pluck('code')->all());
    }

    public function test_the_seller_summary_is_stored_and_a_blank_net_is_sale_minus_returns(): void
    {
        Seller::create(['company_id' => $this->alfa->id, 'name' => 'IRIS CALERO', 'normalized_name' => Seller::normalize('IRIS CALERO')]);

        $batch = $this->imported('seller_summary', self::SUMMARY, [
            $this->seller(),
            $this->seller(['Nombre del Vendedor' => 'TIENDA IVAN', 'Venta Neta' => null, 'Devoluciones' => 100, 'Venta' => 1000, 'Total' => 1050, 'Impuesto' => 150, 'Venta Bruta' => 1200]),
        ]);

        $this->assertSame(2, SellerSalesSummary::count());
        $iris = SellerSalesSummary::where('seller_name', 'IRIS CALERO')->sole();
        $this->assertEquals(['2026-08-01', '2026-08-31', 1000, 3], [$iris->period_from->toDateString(), $iris->cutoff_on->toDateString(), $iris->net_sale, $iris->invoices_count]);
        $this->assertNotNull($iris->seller_id);
        $tienda = SellerSalesSummary::where('seller_name', 'TIENDA IVAN')->sole();
        $this->assertEquals(900, $tienda->net_sale);
        $this->assertNull($tienda->seller_id);
        $this->assertSame(['TIENDA IVAN'], $batch->summary['seller_summary']['unknown_sellers']);
        $this->assertSame('1900.0000', $batch->summary['seller_summary']['net_sale']);
    }

    public function test_a_new_file_for_the_same_cutoff_replaces_the_previous_summary(): void
    {
        $this->imported('seller_summary', self::SUMMARY, [$this->seller()]);
        $batch = $this->imported('seller_summary', self::SUMMARY, [$this->seller(['Venta Neta' => 1000, 'No.Fact.' => 5])]);

        $this->assertSame(1, SellerSalesSummary::count());
        $this->assertSame(5, SellerSalesSummary::sole()->invoices_count);
        $this->assertSame(1, $batch->summary['seller_summary']['replaced']);
    }

    public function test_the_summary_skips_total_rows_and_rejects_a_repeated_seller(): void
    {
        $batch = $this->imported('seller_summary', self::SUMMARY, [$this->seller(), $this->seller(['Nombre del Vendedor' => 'Total'])]);
        $this->assertSame(1, SellerSalesSummary::count());
        $this->assertSame(1, $batch->warnings_total);

        $rejected = $this->validate('seller_summary', self::SUMMARY, [$this->seller(), $this->seller(['Venta Neta' => 5])]);
        $this->assertSame(ImportStatus::Rejected, $rejected->status);
        $this->assertContains('duplicate_seller', $rejected->errors()->pluck('code')->all());
    }

    public function test_totals_that_do_not_add_up_are_only_a_warning(): void
    {
        $batch = $this->imported('seller_summary', self::SUMMARY, [$this->seller(['Total' => 999])]);

        $this->assertSame(['total_mismatch'], $batch->errors()->pluck('code')->all());
    }

    public function test_documents_with_the_fa_series_are_registered_as_voided_and_linked_to_their_sale(): void
    {
        $branch = Branch::factory()->create(['company_id' => $this->alfa->id]);
        $this->documentFor($branch, '19845');

        $batch = $this->imported('billing_detail', self::DETAIL, [
            $this->detail(['Docu.No.' => 19845]), $this->detail(['Docu.No.' => 19845, 'Serie' => 'FA']),
            $this->detail(['Docu.No.' => 19846]), $this->detail(['Docu.No.' => 19847, 'Serie' => 'FA']),
        ]);

        $this->assertSame(['19845', '19847'], SalesDocumentVoid::orderBy('document')->pluck('document')->all());
        $this->assertNotNull(SalesDocumentVoid::where('document', '19845')->value('sales_document_id'));
        $this->assertNull(SalesDocumentVoid::where('document', '19847')->value('sales_document_id'));
        $this->assertSame([2, 2, 1], [$batch->summary['billing']['voids_in_file'], $batch->summary['billing']['voids_new'], $batch->summary['billing']['voids_with_sales']]);

        // Repetir la carga no duplica las anuladas.
        $again = $this->imported('billing_detail', self::DETAIL, [$this->detail(['Docu.No.' => 19847, 'Serie' => 'FA']), $this->detail(['Docu.No.' => 19848, 'Serie' => 'FA'])]);
        $this->assertSame(3, SalesDocumentVoid::count());
        $this->assertSame(1, $again->summary['billing']['voids_new']);
    }

    private function documentFor(Branch $branch, string $number): void
    {
        $seller = Seller::create(['company_id' => $this->alfa->id, 'name' => 'S', 'normalized_name' => 'S']);
        $operator = Operator::create(['company_id' => $this->alfa->id, 'name' => 'O', 'normalized_name' => 'O']);
        $batch = ImportBatch::factory()->create();
        DB::table('sales_documents')->insert([
            'company_id' => $this->alfa->id, 'branch_id' => $branch->id, 'series' => 'FC-A', 'number' => $number, 'issued_on' => '2026-08-03', 'customer_code' => '1', 'customer_name' => 'C',
            'seller_id' => $seller->id, 'operator_id' => $operator->id, 'import_batch_id' => $batch->id, 'lines_count' => 1, 'content_hash' => 'x', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
