<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader;
use Tests\TestCase;

class SalesStatsApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $a1;

    private Branch $a2;

    private Branch $b1;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();

        $this->a = Company::factory()->create(['code' => 'A']);
        $this->b = Company::factory()->create(['code' => 'B']);
        $this->a1 = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Centro']);
        $this->a2 = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Norte']);
        $this->b1 = Branch::factory()->create(['company_id' => $this->b->id, 'name' => 'Sur']);

        // Una factura de dos líneas (la segunda sin costo válido), dos de una línea, una de otra compañía y una del periodo previo.
        $this->doc($this->a1, 'A-1', '2026-10-10', 'C1', [['100', '60', 'Filtros', 'P1'], ['50', '0', 'Aceites', 'P2']]);
        $this->doc($this->a1, 'A-2', '2026-10-12', 'C1', [['200', '100', 'Filtros', 'P1']]);
        $this->doc($this->a2, 'A-3', '2026-10-15', 'C2', [['300', '150', 'Frenos', 'P3']]);
        $this->doc($this->b1, 'B-1', '2026-10-11', 'C9', [['1000', '400', 'Frenos', 'P3']]);
        $this->doc($this->a1, 'A-00', '2026-09-01', 'C3', [['10', '5', 'Filtros', 'P1']]);   // historial anterior al periodo previo
        $this->doc($this->a1, 'A-0', '2026-10-05', 'C3', [['200', '80', 'Filtros', 'P1']]);
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: string}> $lines net, costo, categoría, producto */
    private function doc(Branch $branch, string $number, string $date, string $customer, array $lines): void
    {
        $seller = Seller::firstOrCreate(['company_id' => $branch->company_id, 'normalized_name' => 'ana'], ['name' => 'Ana']);
        $doc = SalesDocument::factory()->create([
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'series' => 'S', 'number' => $number,
            'issued_on' => $date, 'customer_code' => $customer, 'customer_name' => "Cliente $customer",
            'seller_id' => $seller->id, 'lines_count' => count($lines),
        ]);

        foreach ($lines as $i => [$net, $cost, $category, $product]) {
            SalesLine::create([
                'sales_document_id' => $doc->id, 'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'issued_on' => $date,
                'line_number' => $i + 1, 'product_code' => $product, 'product_name' => "Producto $product", 'category_name' => $category,
                'units' => 2, 'unit_cost' => 0, 'line_cost_total' => $cost, 'unit_price' => 0, 'net_total' => $net,
                'tax_total' => $net * 0.12, 'sale_plus_tax' => $net * 1.12, 'grand_total' => $net * 1.12,
            ]);
        }
    }

    private function range(array $params = []): array
    {
        return ['from' => '2026-10-10', 'to' => '2026-10-16', ...$params];
    }

    public function test_summary_counts_documents_once_and_separates_net_tax_and_total(): void
    {
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/summary?'.http_build_query($this->range(['company_id' => $this->a->id])))
            ->assertOk()->json('data.current');

        $this->assertSame('650.00', $data['net_total']);
        $this->assertSame('78.00', $data['tax_total']);
        $this->assertSame('728.00', $data['grand_total']);
        $this->assertSame('8.00', $data['units']);       // 4 líneas × 2
        $this->assertSame(3, $data['documents']);         // 4 líneas, 3 facturas
        $this->assertSame(2, $data['customers']);
        $this->assertSame('216.67', $data['average_ticket']);
    }

    public function test_margin_only_uses_lines_with_valid_cost_and_reports_coverage(): void
    {
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/summary?'.http_build_query($this->range(['company_id' => $this->a->id])))
            ->json('data');

        $this->assertSame('290.00', $data['current']['estimated_margin']);    // (100+200+300) − (60+100+150)
        $this->assertSame(48.33, $data['current']['estimated_margin_pct']);
        $this->assertSame(92.31, $data['current']['cost_coverage_pct']);      // 600 de 650 con costo
        $this->assertSame('estimated_with_current_cost', $data['notes']['margin']);
    }

    public function test_previous_period_is_equivalent_and_change_is_computed(): void
    {
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/summary?'.http_build_query($this->range(['company_id' => $this->a->id])))
            ->json('data');

        $this->assertSame(['from' => '2026-10-03', 'to' => '2026-10-09', 'days' => 7], $data['previous_period']);
        $this->assertSame('200.00', $data['previous']['net_total']);
        $this->assertEquals(225.0, $data['change_pct']['net_total']);
        $this->assertSame([], $data['warnings']);
    }

    public function test_incomplete_history_is_flagged(): void
    {
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/summary?from=2026-09-01&to=2026-10-16')
            ->json('data');

        $this->assertContains('previous_period_incomplete', $data['warnings']);
        $this->assertNull($data['change_pct']['net_total']);                  // sin base de comparación
    }

    public function test_breakdown_by_company_and_branch(): void
    {
        $byCompany = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/breakdown?'.http_build_query($this->range(['dimension' => 'company'])))
            ->assertOk()->json('data');

        $this->assertSame([$this->b->name, $this->a->name], array_column($byCompany, 'label'));  // por venta neta desc
        $this->assertSame(['1000.00', '650.00'], array_column($byCompany, 'net_total'));

        $byBranch = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/breakdown?'.http_build_query($this->range(['dimension' => 'branch', 'company_id' => $this->a->id])))
            ->json('data');

        $this->assertSame(['Centro', 'Norte'], array_column($byBranch, 'label'));
        $this->assertSame([2, 1], array_column($byBranch, 'documents'));
    }

    public function test_breakdown_by_product_category_seller_customer_and_month(): void
    {
        $get = fn (string $dimension, array $extra = []) => $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/breakdown?'.http_build_query($this->range(['dimension' => $dimension, 'company_id' => $this->a->id, ...$extra])))
            ->assertOk()->json('data');

        $products = $get('product', ['sort' => 'units']);
        $this->assertSame(['P1', 'P2', 'P3'], array_column($products, 'key'));    // P1: 2 líneas (4 u); P3 y P2: 2 u; empate por clave
        $this->assertSame('Producto P1', $products[0]['label']);

        $this->assertSame(['Filtros', 'Frenos', 'Aceites'], array_column($get('category'), 'key'));
        $this->assertSame(['Ana'], array_column($get('seller'), 'label'));
        $this->assertSame(['C1', 'C2'], array_column($get('customer'), 'key'));
        $this->assertSame(['2026-10'], array_column($get('month'), 'key'));
        $this->assertSame(['2026-10-10', '2026-10-12', '2026-10-15'], array_column($get('day'), 'key'));
        $this->assertSame(['Operador Demo'], array_column($get('operator'), 'label'));
    }

    public function test_breakdown_limit_marks_truncation(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/breakdown?'.http_build_query($this->range(['dimension' => 'customer', 'limit' => 1])))
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertTrue($response->json('meta.truncated'));
    }

    public function test_filters_combine(): void
    {
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/summary?'.http_build_query($this->range(['branch_id' => $this->a1->id, 'category' => 'Filtros'])))
            ->json('data.current');

        $this->assertSame('300.00', $data['net_total']);
        $this->assertSame(2, $data['documents']);
    }

    public function test_user_only_sees_their_scope(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $user->branches()->attach($this->a2);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['sales.view']])->assertOk();

        $own = $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales/summary?'.http_build_query($this->range()))->assertOk();
        $this->assertSame('300.00', $own->json('data.current.net_total'));          // solo sucursal Norte

        // Pedir explícitamente otra compañía o sucursal no abre nada.
        $other = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sales/summary?'.http_build_query($this->range(['company_id' => $this->b->id])))->json('data.current');
        $this->assertSame('0.00', $other['net_total']);
        $this->assertSame(0, $other['documents']);
        $this->assertNull($other['average_ticket']);

        $branches = $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/sales/breakdown?'.http_build_query($this->range(['dimension' => 'branch'])))->json('data');
        $this->assertSame(['Norte'], array_column($branches, 'label'));
    }

    public function test_requires_the_permission(): void
    {
        $user = User::factory()->role(Role::VENDEDOR)->create();
        $user->companies()->attach($this->a);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales/summary?'.http_build_query($this->range()))->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/sales/breakdown?'.http_build_query($this->range(['dimension' => 'company'])))->assertForbidden();
    }

    public function test_validation(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->getJson('/api/v1/sales/summary')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/v1/sales/summary?from=2026-10-10&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->getJson('/api/v1/sales/summary?from=2020-01-01&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->getJson('/api/v1/sales/breakdown?'.http_build_query($this->range()))->assertUnprocessable()->assertJsonValidationErrors(['dimension']);
        $this->getJson('/api/v1/sales/breakdown?'.http_build_query($this->range(['dimension' => 'x'])))->assertUnprocessable();
        $this->getJson('/api/v1/sales/breakdown?'.http_build_query($this->range(['dimension' => 'company', 'sort' => 'name'])))->assertUnprocessable();
    }

    /** @return array<string, list<list<mixed>>> hojas del archivo, por nombre */
    private function sheets(string $path): array
    {
        $reader = new Reader;
        $reader->open($path);
        $out = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $out[$sheet->getName()][] = $row->toArray();
            }
        }
        $reader->close();

        return $out;
    }

    public function test_csv_export_matches_the_breakdown_and_respects_the_scope_filters(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/sales/export?'.http_build_query($this->range(['format' => 'csv', 'dimension' => 'branch', 'company_id' => $this->a->id])))
            ->assertOk();

        $this->assertStringContainsString('text/csv', $response->headers->get('content-type'));
        $this->assertStringContainsString('ventas-por-branch-2026-10-10_2026-10-16.csv', $response->headers->get('content-disposition'));

        $lines = array_map('str_getcsv', array_filter(explode("\n", ltrim($response->streamedContent(), "\xEF\xBB\xBF"))));
        $this->assertSame(['Sucursal', 'Clave', 'Venta neta'], array_slice($lines[0], 0, 3));
        $this->assertSame(['Centro', (string) $this->a1->id, '350.00'], array_slice($lines[1], 0, 3));
        $this->assertSame(['Norte', (string) $this->a2->id, '300.00'], array_slice($lines[2], 0, 3));
        $this->assertCount(3, $lines);                                          // sin la sucursal de la otra compañía
    }

    public function test_csv_neutralizes_text_that_would_be_read_as_a_formula(): void
    {
        $this->doc($this->a1, 'A-9', '2026-10-13', 'C7', [['10', '5', '=HYPERLINK("x")', 'P9']]);

        $csv = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/sales/export?'.http_build_query($this->range(['format' => 'csv', 'dimension' => 'category', 'company_id' => $this->a->id])))
            ->streamedContent();

        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString("\n=HYPERLINK", $csv);
    }

    public function test_excel_export_has_summary_and_every_breakdown_with_numeric_amounts(): void
    {
        $response = $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/sales/export?'.http_build_query($this->range(['format' => 'xlsx', 'company_id' => $this->a->id, 'category' => 'Filtros'])))
            ->assertOk();

        $this->assertStringContainsString('ventas-2026-10-10_2026-10-16.xlsx', $response->headers->get('content-disposition'));
        $sheets = $this->sheets($response->baseResponse->getFile()->getPathname());

        $this->assertSame(
            ['Resumen', 'Por compañía', 'Por sucursal', 'Por vendedor', 'Por operador', 'Por categoría', 'Por producto', 'Por cliente', 'Por mes', 'Por día'],
            array_keys($sheets),
        );

        $summary = array_column($sheets['Resumen'], 1, 0);
        $this->assertSame('2026-10-10 a 2026-10-16', $summary['Periodo']);
        $this->assertStringContainsString('Compañía: '.$this->a->name, $summary['Filtros']);
        $this->assertStringContainsString('Categoría: Filtros', $summary['Filtros']);
        $this->assertEquals(300.0, $summary['Venta neta']);                      // 100 + 200: solo la categoría filtrada
        $this->assertIsNumeric($summary['Venta neta']);
        $this->assertSame(2, $summary['Documentos']);

        $this->assertSame('Centro', $sheets['Por sucursal'][1][0]);
        $this->assertEquals(300.0, $sheets['Por sucursal'][1][2]);
        $this->assertSame(['2026-10-10', '2026-10-12'], array_column(array_slice($sheets['Por día'], 1), 0));
    }

    public function test_excel_export_only_contains_data_in_the_users_scope(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $user->branches()->attach($this->a2);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['sales.view']])->assertOk();

        $response = $this->actingAs($user, 'sanctum')->get('/api/v1/sales/export?'.http_build_query($this->range(['format' => 'xlsx'])))->assertOk();
        $sheets = $this->sheets($response->baseResponse->getFile()->getPathname());

        $this->assertEquals(300.0, array_column($sheets['Resumen'], 1, 0)['Venta neta']);
        $this->assertSame(['Norte'], array_column(array_slice($sheets['Por sucursal'], 1), 0));
    }

    public function test_exports_are_audited_and_require_the_permission_and_valid_input(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->get('/api/v1/sales/export?'.http_build_query($this->range(['format' => 'csv', 'dimension' => 'seller'])))->assertOk()->streamedContent();

        $log = AuditLog::where('event', 'sales.exported')->sole();
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('csv', $log->new_values['format']);
        $this->assertSame('seller', $log->new_values['dimension']);
        $this->assertSame('2026-10-10', $log->new_values['from']);

        $this->getJson('/api/v1/sales/export?'.http_build_query($this->range(['format' => 'csv'])))->assertUnprocessable()->assertJsonValidationErrors(['dimension']);
        $this->getJson('/api/v1/sales/export?'.http_build_query($this->range(['format' => 'pdf'])))->assertUnprocessable()->assertJsonValidationErrors(['format']);
        $this->getJson('/api/v1/sales/export?'.http_build_query($this->range()))->assertUnprocessable()->assertJsonValidationErrors(['format']);
        $this->assertSame(1, AuditLog::where('event', 'sales.exported')->count());      // lo inválido no se audita

        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $seller->companies()->attach($this->a);
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/sales/export?'.http_build_query($this->range(['format' => 'xlsx'])))->assertForbidden();
        $this->assertSame(1, AuditLog::where('event', 'sales.exported')->count());
    }
}
