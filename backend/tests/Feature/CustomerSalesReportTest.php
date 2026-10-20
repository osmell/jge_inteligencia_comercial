<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerSalesReportTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $centro;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->centro = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Centro']);
        $ana = Seller::create(['company_id' => $this->a->id, 'name' => 'Ana', 'normalized_name' => 'ana']);
        $luis = Seller::create(['company_id' => $this->a->id, 'name' => 'Luis', 'normalized_name' => 'luis']);
        $caja = Operator::create(['company_id' => $this->a->id, 'name' => 'Caja 1', 'normalized_name' => 'caja 1']);
        $luisOp = Operator::create(['company_id' => $this->a->id, 'name' => 'Luis', 'normalized_name' => 'luis']);
        Seller::ensureStoreSeller($this->centro);

        Customer::create(['company_id' => $this->a->id, 'branch_id' => $this->centro->id, 'code' => 'C1', 'name' => 'Cliente 1', 'source' => 'import', 'seller_id' => $ana->id]);
        Customer::create(['company_id' => $this->a->id, 'branch_id' => $this->centro->id, 'code' => 'C2', 'name' => '=Cliente 2', 'source' => 'import']);
        Customer::create(['company_id' => $this->a->id, 'branch_id' => $this->centro->id, 'code' => 'C3', 'name' => 'Sin ventas', 'source' => 'import']);
        $this->sale('C1', $ana, $caja, '2026-10-02', 100);   // Ana vendió, Caja 1 facturó
        $this->sale('C1', $luis, $luisOp, '2026-10-03', 50); // Luis facturó su propia venta (mismo nombre)
        $this->sale('C1', $ana, $caja, '2026-09-01', 999);   // fuera del periodo
        $this->sale('C2', $ana, $caja, '2026-10-05', 30);
    }

    private function sale(string $code, Seller $seller, Operator $op, string $date, int $net): void
    {
        $doc = SalesDocument::factory()->create([
            'company_id' => $this->a->id, 'branch_id' => $this->centro->id, 'series' => 'S', 'number' => 'N'.++$this->seq, 'issued_on' => $date,
            'customer_code' => $code, 'seller_id' => $seller->id, 'operator_id' => $op->id, 'lines_count' => 1,
        ]);
        SalesLine::create([
            'sales_document_id' => $doc->id, 'company_id' => $this->a->id, 'branch_id' => $this->centro->id, 'issued_on' => $date, 'line_number' => 1,
            'product_code' => 'P', 'product_name' => 'P', 'category_name' => 'Cat', 'units' => 1, 'unit_cost' => 0, 'line_cost_total' => 0, 'unit_price' => 0,
            'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net,
        ]);
    }

    private function url(string $path = '', array $extra = []): string
    {
        return '/api/v1/customers/sales-report'.$path.'?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31'] + $extra);
    }

    public function test_report_shows_who_sold_who_invoiced_and_direct_sales(): void
    {
        $rows = collect($this->actingAs($this->admin, 'sanctum')->getJson($this->url())->assertOk()->assertJsonPath('meta.returns', 'not_included')->json('data'))->keyBy('code');

        $this->assertSame(['C1', 'C2'], $rows->keys()->all());       // por venta neta, sin el cliente sin ventas
        $c1 = $rows['C1'];
        $this->assertSame('150.00', $c1['net_total']);
        $this->assertSame(2, $c1['documents']);
        $this->assertSame('50.00', $c1['direct_net_total']);
        $this->assertSame([['name' => 'Ana', 'net_total' => '100.00'], ['name' => 'Luis', 'net_total' => '50.00']], $c1['sellers']);
        $this->assertSame([['name' => 'Caja 1', 'net_total' => '100.00'], ['name' => 'Luis', 'net_total' => '50.00']], $c1['operators']);
        $this->assertSame('Ana', $c1['assigned_seller']);
        $this->assertSame('2026-10-03', $c1['last_purchase']);

        // C2 no tiene vendedor asignado: se muestra la Tienda de su sucursal.
        $this->assertSame('Tienda Centro', $rows['C2']['assigned_seller']);
        $this->assertTrue($rows['C2']['assigned_seller_is_default']);
        $this->assertSame('0.00', $rows['C2']['direct_net_total']);
    }

    public function test_csv_and_xlsx_exports_are_audited_and_neutralize_formulas(): void
    {
        $csv = $this->actingAs($this->admin, 'sanctum')->get($this->url('/export', ['format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('Quién vendió', $csv);
        $this->assertStringContainsString("'=Cliente 2", $csv);
        $this->assertStringContainsString('Ana (100.00); Luis (50.00)', $csv);

        $this->get($this->url('/export', ['format' => 'xlsx']))->assertOk()->assertHeader('content-disposition');
        $this->assertSame(2, AuditLog::where('event', 'customers.sales_report_exported')->count());
        $this->get($this->url('/export', ['format' => 'pdf']))->assertUnprocessable();
        $this->get($this->url('/export'))->assertUnprocessable();
    }

    public function test_validation_and_permission(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/customers/sales-report?from=2026-10-31&to=2026-10-01')->assertUnprocessable();

        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->actingAs($user, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['sales.view']])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['sales.view']])->assertOk();
        $user->unsetRelations();
        $this->actingAs($user, 'sanctum')->getJson($this->url())->assertForbidden();
    }

    public function test_a_seller_user_only_reports_their_customers(): void
    {
        $vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $vendedor->companies()->attach($this->a);
        Seller::where('normalized_name', 'ana')->first()->update(['user_id' => $vendedor->id]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$vendedor->role_id, ['permissions' => ['customers.view']])->assertOk();
        $vendedor->unsetRelations();

        $codes = collect($this->actingAs($vendedor, 'sanctum')->getJson($this->url())->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['C1'], $codes);   // C2 no tiene vendedor asignado
    }
}
