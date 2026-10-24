<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesFixtures;
use Tests\TestCase;

/** Reporte de evaluación de sucursales: cada cifra contra ventas, cartera e inventario declarados a mano. */
class BranchReportTest extends TestCase
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
        $this->travelTo('2026-10-25 12:00:00');
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
        return $this->actingAs($as ?? $this->manager, 'sanctum')->getJson('/api/v1/reports/branches?'.http_build_query($query + ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function rowOf(array $report, Branch $branch): array
    {
        foreach ($report['rows'] as $row) {
            if ($row['branch']['id'] === $branch->id) {
                return $row;
            }
        }
        $this->fail("El reporte no trae la sucursal {$branch->name}.");
    }

    private function supervisorOf(Branch ...$branches): User
    {
        $supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $supervisor->companies()->sync([$this->alfa->id]);
        $supervisor->branches()->sync(array_map(fn (Branch $b) => $b->id, $branches));

        return $supervisor;
    }

    public function test_sales_figures_growth_and_ranking_per_branch(): void
    {
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0], ['net' => 400.0]]);
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-20', 'C2', [['net' => 500.0]]);
        $this->sale($this->ana, $this->operator, $this->norte, '2026-10-10', 'C1', [['net' => 500.0]]);
        $this->sale($this->ana, $this->operator, $this->centro, '2026-09-15', 'C1', [['net' => 1000.0]]);

        $report = $this->report();
        $centro = $this->rowOf($report, $this->centro);

        $this->assertEquals(1500.0, $centro['sales']['net_total']);
        $this->assertSame(2, $centro['sales']['documents']);
        $this->assertSame(2, $centro['sales']['customers']);
        $this->assertEquals(750.0, $centro['sales']['average_ticket']);
        $this->assertEquals(50.0, $centro['sales']['growth_pct']);
        $this->assertEquals(75.0, $centro['sales']['share_pct']);
        $this->assertSame(1, $centro['rank']);
        $this->assertSame(2, $this->rowOf($report, $this->norte)['rank']);
        $this->assertEquals(2000.0, $report['totals']['net_total']);
        $this->assertSame(2, $report['totals']['branches']);
        $this->assertTrue($report['reconciliation']['matches']);
    }

    public function test_dependency_on_the_top_seller_ignores_the_store_counter_seller(): void
    {
        $luis = $this->seller($this->alfa, 'Luis');
        $store = Seller::ensureStoreSeller($this->centro);
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0]]);
        $this->sale($luis, $this->operator, $this->centro, '2026-10-06', 'C2', [['net' => 200.0]]);
        $this->sale($store, $this->operator, $this->centro, '2026-10-07', 'C3', [['net' => 200.0]]);
        $this->sale($store, $this->operator, $this->norte, '2026-10-07', 'C4', [['net' => 300.0]]);   // Norte solo vende de mostrador

        $report = $this->report();
        $centro = $this->rowOf($report, $this->centro)['dependency'];
        $norte = $this->rowOf($report, $this->norte)['dependency'];

        $this->assertSame('Ana', $centro['top_seller']);
        $this->assertEquals(60.0, $centro['top_seller_pct']);
        $this->assertSame(2, $centro['sellers_count']);
        $this->assertSame('warn', $this->rowOf($report, $this->centro)['semaphores']['top_seller_dependency_pct']);
        $this->assertNull($norte['top_seller']);
        $this->assertNull($norte['top_seller_pct'], 'Una sucursal que solo vende de mostrador no depende de ninguna persona.');
        $this->assertSame('none', $this->rowOf($report, $this->norte)['semaphores']['top_seller_dependency_pct']);
    }

    public function test_customer_concentration_per_branch(): void
    {
        foreach (['C1' => 400, 'C2' => 300, 'C3' => 200, 'C4' => 60, 'C5' => 40] as $code => $net) {
            $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', $code, [['net' => (float) $net]]);
        }

        $this->assertEquals(90.0, $this->rowOf($this->report(), $this->centro)['sales']['top3_concentration_pct']);
    }

    public function test_the_portfolio_belongs_to_the_branch_that_billed_each_customer_last(): void
    {
        $this->sale($this->ana, $this->operator, $this->centro, '2026-01-02', 'C0', [['net' => 1.0]]);                  // historial anterior
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-20', 'ACTIVO', [['net' => 10.0]]);
        $this->sale($this->ana, $this->operator, $this->centro, '2026-05-01', 'PERDIDO', [['net' => 10.0]]);
        $this->sale($this->ana, $this->operator, $this->centro, '2026-03-01', 'MOVIDO', [['net' => 10.0]]);
        $this->sale($this->ana, $this->operator, $this->norte, '2026-10-21', 'MOVIDO', [['net' => 10.0]]);               // ahora compra en Norte

        $report = $this->report();
        $centro = $this->rowOf($report, $this->centro)['portfolio'];
        $norte = $this->rowOf($report, $this->norte)['portfolio'];

        $this->assertSame(3, $centro['total']);   // C0 (perdido), ACTIVO y PERDIDO
        $this->assertSame(2, $centro['lost']);
        $this->assertEquals(round(2 / 3 * 100, 2), $centro['lost_pct']);
        $this->assertSame(1, $norte['total']);
        $this->assertSame(0, $norte['lost']);
    }

    public function test_credit_balance_and_overdue_share_per_branch(): void
    {
        $this->invoice($this->centro, '2026-10-15', 600.0, 0.0);
        $this->invoice($this->centro, '2026-07-01', 1000.0, 600.0);   // vencida: saldo 400
        $this->invoice($this->norte, '2026-07-01', 100.0, 0.0);

        $report = $this->report();
        $credit = $this->rowOf($report, $this->centro)['credit'];

        $this->assertEquals(1000.0, $credit['balance']);
        $this->assertEquals(400.0, $credit['overdue']);
        $this->assertEquals(40.0, $credit['overdue_pct']);
        $this->assertEquals(100.0, $this->rowOf($report, $this->norte)['credit']['overdue_pct']);
        $this->assertEquals(500.0, $report['totals']['credit_overdue']);
    }

    private function invoice(Branch $branch, string $date, float $total, float $paid): void
    {
        $batch = DB::table('import_batches')->value('id') ?? ImportBatch::factory()->create()->id;
        $id = DB::table('credit_invoices')->insertGetId([
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'series' => 'F', 'number' => (string) random_int(1, 999999), 'issued_on' => $date,
            'customer_code' => 'C1', 'customer_name' => 'Cliente', 'seller_id' => $this->ana->id, 'operator_id' => $this->operator->id, 'subtotal' => $total,
            'tax_total' => 0, 'total' => $total, 'import_batch_id' => $batch, 'content_hash' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('credit_movements')->insert(['company_id' => $branch->company_id, 'credit_invoice_id' => $id, 'type' => 'invoice', 'amount' => $total, 'occurred_on' => $date, 'created_at' => now()]);
        if ($paid > 0) {
            DB::table('credit_movements')->insert(['company_id' => $branch->company_id, 'credit_invoice_id' => $id, 'type' => 'payment', 'amount' => -$paid, 'occurred_on' => $date, 'created_at' => now()]);
        }
    }

    private function product(string $code): int
    {
        return DB::table('products')->insertGetId(['company_id' => $this->alfa->id, 'code' => $code, 'name' => "Producto $code", 'created_at' => now(), 'updated_at' => now()]);
    }

    private function stock(Branch $branch, int $productId, float $quantity): void
    {
        DB::table('inventory_balances')->insert(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'product_id' => $productId, 'quantity' => $quantity, 'value' => $quantity * 10]);
    }

    public function test_inventory_figures_count_products_not_money(): void
    {
        $p1 = $this->product('P1');
        $p2 = $this->product('P2');
        $p3 = $this->product('P3');
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-20', 'C1', [['net' => 10.0], ['net' => 10.0]]);   // vende P1 y P2
        $this->stock($this->centro, $p1, 5);    // con existencia y rotando
        $this->stock($this->centro, $p2, 0);    // vendido y agotado
        $this->stock($this->centro, $p3, 7);    // con existencia y sin ventas: sin rotación
        $sale = DB::table('sales_lines')->value('id');
        foreach ([[1, null], [2, null], [3, now()]] as [$n, $resolved]) {
            DB::table('inventory_shortfalls')->insert([
                'company_id' => $this->alfa->id, 'branch_id' => $this->centro->id, 'sales_line_id' => 1000 + $n, 'product_code' => 'P1', 'issued_on' => '2026-10-20',
                'requested' => 5, 'applied' => 3, 'missing' => 2, 'reason' => 'no_stock', 'resolved_at' => $resolved, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $report = $this->report();
        $inventory = $this->rowOf($report, $this->centro)['inventory'];

        $this->assertSame(2, $inventory['products_in_stock']);
        $this->assertSame(1, $inventory['no_rotation']);
        $this->assertEquals(50.0, $inventory['no_rotation_pct']);
        $this->assertSame(2, $inventory['sold_products']);
        $this->assertSame(1, $inventory['stockout_with_demand']);
        $this->assertEquals(50.0, $inventory['stockout_demand_pct']);
        $this->assertSame(2, $inventory['open_shortfalls']);
        $this->assertSame('bad', $this->rowOf($report, $this->centro)['semaphores']['no_rotation_pct']);
        $this->assertEqualsCanonicalizing(['products_in_stock', 'no_rotation', 'no_rotation_pct', 'sold_products', 'stockout_with_demand', 'stockout_demand_pct', 'open_shortfalls'], array_keys($inventory), 'Ningún importe ni costo: eso exige inventory.costs.');
        $this->assertNotNull($sale);
        // Una sucursal sin inventario cargado queda «sin dato», no en cero.
        $norte = $this->rowOf($report, $this->norte)['inventory'];
        $this->assertNull($norte['no_rotation_pct']);
        $this->assertSame('none', $this->rowOf($report, $this->norte)['semaphores']['no_rotation_pct']);
    }

    public function test_the_category_mix_shows_which_branch_over_or_under_sells_each_category(): void
    {
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 80.0, 'category' => 'A'], ['net' => 20.0, 'category' => 'B']]);
        $this->sale($this->ana, $this->operator, $this->norte, '2026-10-06', 'C2', [['net' => 20.0, 'category' => 'A'], ['net' => 80.0, 'category' => 'B']]);

        $mix = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/branches/mix?from=2026-10-01&to=2026-10-31')->assertOk()->json('data');

        $this->assertSame(['Centro', 'Norte'], array_column($mix['branches'], 'name'));
        $a = collect($mix['categories'])->firstWhere('name', 'A');
        $this->assertEquals(50.0, $a['share_pct']);
        $this->assertEquals([80.0, 20.0], array_column($a['cells'], 'share_pct'));
        $this->assertSame([160, 40], array_column($a['cells'], 'index'));
        $this->assertSame(2, $mix['total_categories']);
    }

    public function test_the_category_mix_only_shows_the_most_sold_categories(): void
    {
        $lines = [];
        for ($i = 1; $i <= 20; $i++) {
            $lines[] = ['net' => (float) (100 - $i), 'category' => sprintf('Cat %02d', $i)];
        }
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', $lines);

        $mix = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/branches/mix?from=2026-10-01&to=2026-10-31')->json('data');

        $this->assertCount(15, $mix['categories']);
        $this->assertSame(20, $mix['total_categories']);
        $this->assertSame('Cat 01', $mix['categories'][0]['name']);
    }

    public function test_a_manager_never_sees_another_company(): void
    {
        $branchBeta = Branch::factory()->create(['company_id' => $this->beta->id, 'name' => 'Beta Centro']);
        $beto = $this->seller($this->beta, 'Beto');
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 100.0]]);
        $this->sale($beto, $this->operator($this->beta, 'Olga'), $branchBeta, '2026-10-05', 'C1', [['net' => 9999.0]]);

        $report = $this->report();

        $this->assertSame(['Centro', 'Norte'], array_column(array_column($report['rows'], 'branch'), 'name'));
        $this->assertEquals(100.0, $report['totals']['net_total']);
        $this->assertSame([], $this->report(['company_id' => $this->beta->id])['rows']);
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/branches/{$branchBeta->id}/documents?from=2026-10-01&to=2026-10-31")->assertNotFound();
        $mix = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/branches/mix?from=2026-10-01&to=2026-10-31')->json('data');
        $this->assertNotContains('Beta Centro', array_column($mix['branches'], 'name'));
    }

    public function test_a_supervisor_only_evaluates_the_branches_assigned_to_them_and_the_contrast_still_matches(): void
    {
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0]]);
        $this->sale($this->ana, $this->operator, $this->norte, '2026-10-06', 'C2', [['net' => 400.0]]);
        $supervisor = $this->supervisorOf($this->centro);

        $report = $this->report(as: $supervisor);

        $this->assertSame(['Centro'], array_column(array_column($report['rows'], 'branch'), 'name'));
        $this->assertEquals(600.0, $report['totals']['net_total']);
        $this->assertTrue($report['reconciliation']['matches'], 'El consolidado se limita a las mismas sucursales.');
        $this->actingAs($supervisor, 'sanctum')->getJson("/api/v1/reports/branches/{$this->norte->id}/documents?from=2026-10-01&to=2026-10-31")->assertNotFound();
        $mix = $this->actingAs($supervisor, 'sanctum')->getJson('/api/v1/reports/branches/mix?from=2026-10-01&to=2026-10-31')->json('data');
        $this->assertSame(['Centro'], array_column($mix['branches'], 'name'));
    }

    public function test_the_billing_detail_of_a_branch_adds_up_to_the_report(): void
    {
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0], ['net' => 400.0]]);
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-20', 'C2', [['net' => 500.0]]);
        $this->sale($this->ana, $this->operator, $this->norte, '2026-10-21', 'C2', [['net' => 777.0]]);

        $detail = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/branches/{$this->centro->id}/documents?from=2026-10-01&to=2026-10-31")->assertOk()->json('data');

        $this->assertSame('Centro', $detail['subject']['name']);
        $this->assertSame(2, $detail['totals']['documents']);
        $this->assertEquals(1500.0, $detail['totals']['net_total']);
        $this->assertEquals($this->rowOf($this->report(), $this->centro)['sales']['net_total'], $detail['totals']['net_total']);
        $this->assertSame(['Centro'], array_values(array_unique(array_column($detail['rows'], 'branch'))));
    }

    public function test_filtering_by_branch_shows_only_that_branch(): void
    {
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0]]);
        $this->sale($this->ana, $this->operator, $this->norte, '2026-10-06', 'C2', [['net' => 400.0]]);

        $report = $this->report(['branch_id' => $this->norte->id]);

        $this->assertSame(['Norte'], array_column(array_column($report['rows'], 'branch'), 'name'));
        $this->assertTrue($report['reconciliation']['matches']);
    }

    public function test_sellers_cannot_open_the_branch_report_and_everything_is_audited(): void
    {
        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $seller->companies()->sync([$this->alfa->id]);
        foreach (['', "/{$this->centro->id}/documents", '/mix'] as $path) {
            $this->actingAs($seller, 'sanctum')->getJson("/api/v1/reports/branches$path?from=2026-10-01&to=2026-10-31")->assertForbidden();
        }

        $this->report(['company_id' => $this->alfa->id]);
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/branches/mix?from=2026-10-01&to=2026-10-31');

        $this->assertSame(['branches', 'branch_mix'], AuditLog::where('event', 'report.viewed')->orderBy('id')->get()->map(fn ($l) => $l->new_values['report'])->all());
    }

    public function test_invalid_queries_are_rejected(): void
    {
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/branches')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/branches/mix?from=2026-10-31&to=2026-10-01')->assertStatus(422);
    }
}
