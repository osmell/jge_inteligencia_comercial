<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Inventory\Quantity;
use App\Jobs\SyncSalesInventoryJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryShortfall;
use App\Models\Operator;
use App\Models\Product;
use App\Models\Role;
use App\Models\Seller;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryLedger;
use App\Services\SalesInventorySync;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SalesInventorySyncTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $central;

    private Branch $norte;

    private User $admin;

    private User $viewer;

    private Product $oil;

    private Seller $seller;

    private Operator $operator;

    private int $batch;

    private int $docs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->viewer = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->viewer->companies()->attach($this->a->id);
        $this->central = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Central']);
        $this->norte = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Norte']);
        $this->oil = Product::create(['company_id' => $this->a->id, 'code' => '100', 'name' => 'Aceite', 'base_unit_id' => Unit::where('code', 'galon')->value('id')]);
        $this->seller = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera']);
        $this->operator = Operator::create(['company_id' => $this->a->id, 'name' => 'Caja', 'normalized_name' => 'caja']);
        $this->batch = ImportBatch::factory()->create(['type' => 'sales', 'user_id' => $this->admin->id])->id;
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->viewer->role_id, ['permissions' => ['inventory.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function opening(Branch $branch, string $qty, string $cutoff = '2026-09-30', string $value = '100'): void
    {
        app(InventoryLedger::class)->record($this->a->id, $branch->id, $this->oil, InventoryMovement::OPENING, $qty, null, Carbon::parse($cutoff), $this->admin, $value);
    }

    /** Inserta una venta de una línea (las ventas son inmutables desde los modelos). */
    private function sale(string $units, string $on, ?Branch $branch = null, string $product = '100'): int
    {
        $now = now();
        $docId = DB::table('sales_documents')->insertGetId([
            'company_id' => $this->a->id, 'branch_id' => ($branch ?? $this->central)->id, 'series' => 'F', 'number' => (string) ++$this->docs, 'issued_on' => $on, 'issued_at' => null,
            'customer_code' => 'C1', 'customer_name' => 'Cliente', 'seller_id' => $this->seller->id, 'operator_id' => $this->operator->id, 'import_batch_id' => $this->batch, 'lines_count' => 1,
            'content_hash' => 'h'.$this->docs, 'created_at' => $now, 'updated_at' => $now,
        ]);

        return DB::table('sales_lines')->insertGetId([
            'sales_document_id' => $docId, 'company_id' => $this->a->id, 'branch_id' => ($branch ?? $this->central)->id, 'issued_on' => $on, 'line_number' => 1,
            'product_code' => $product, 'product_name' => 'Aceite', 'category_name' => 'Lubricantes', 'units' => $units, 'unit_cost' => 10, 'line_cost_total' => 10, 'unit_price' => 20, 'normal_price' => 20,
            'net_total' => 20, 'tax_total' => 3, 'sale_plus_tax' => 23, 'grand_total' => 23, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    private function sync(): array
    {
        return app(SalesInventorySync::class)->run();
    }

    private function stock(Branch $branch): string
    {
        return Quantity::display(app(InventoryLedger::class)->balance($this->a->id, $branch->id, $this->oil->id)[0]);
    }

    public function test_sales_after_the_cutoff_discount_stock_once_and_resyncing_does_not_duplicate(): void
    {
        $this->opening($this->central, '10');
        $line = $this->sale('3', '2026-10-05');
        $this->sale('2.5', '2026-10-06');

        $this->assertSame(['branches' => 1, 'lines' => 2, 'movements' => 2, 'shortfalls' => 0], $this->sync());
        $this->assertSame('4.5', $this->stock($this->central));
        $m = InventoryMovement::where('source_key', "sale:$line")->sole();
        $this->assertSame(['sale', '-3.00000000', '-30.0000', '2026-10-05', 'Venta F-1'], [$m->type, $m->quantity, $m->value, $m->occurred_on->toDateString(), $m->reference]);

        $this->assertSame(['branches' => 1, 'lines' => 0, 'movements' => 0, 'shortfalls' => 0], $this->sync());
        $this->assertSame('4.5', $this->stock($this->central));
    }

    public function test_sales_on_or_before_the_cutoff_and_branches_without_opening_are_ignored(): void
    {
        $this->opening($this->central, '10', '2026-09-30');
        $this->sale('1', '2026-09-30');                      // ya contada en el conteo
        $this->sale('1', '2026-09-15');
        $this->sale('4', '2026-10-01', $this->norte);        // sin inventario inicial

        $this->assertSame(0, $this->sync()['lines']);
        $this->assertSame('10', $this->stock($this->central));
        $this->assertSame(0, InventoryShortfall::count());
    }

    public function test_a_sale_without_enough_stock_discounts_what_there_is_and_records_the_shortfall(): void
    {
        $this->opening($this->central, '4');
        $line = $this->sale('3', '2026-10-05');
        $big = $this->sale('3', '2026-10-06');               // solo queda 1

        $result = $this->sync();
        $this->assertSame([2, 2, 1], [$result['lines'], $result['movements'], $result['shortfalls']]);
        $this->assertSame('0', $this->stock($this->central));
        $s = InventoryShortfall::sole();
        $this->assertSame([$big, 'insufficient', '3', '1', '2', false], [$s->sales_line_id, $s->reason, Quantity::display(Quantity::units($s->requested)), Quantity::display(Quantity::units($s->applied)), Quantity::display(Quantity::units($s->missing)), $s->resolved_at !== null]);
        $this->assertNotNull($line);

        // Sin existencias: no hay movimiento, solo faltante, y el saldo nunca es negativo.
        $this->sale('2', '2026-10-07');
        $this->assertSame(1, $this->sync()['shortfalls']);
        $this->assertSame(0, DB::table('inventory_balances')->where('quantity', '<', 0)->count());
    }

    public function test_a_product_that_is_not_in_the_catalog_or_has_no_base_unit_is_a_shortfall(): void
    {
        $this->opening($this->central, '5');
        Product::create(['company_id' => $this->a->id, 'code' => '300', 'name' => 'Sin base']);
        $this->sale('1', '2026-10-05', null, '999');
        $this->sale('1', '2026-10-05', null, '300');

        $this->sync();
        $this->assertEqualsCanonicalizing(['no_product', 'no_base_unit'], InventoryShortfall::pluck('reason')->all());
        $this->assertSame('5', $this->stock($this->central));
    }

    public function test_reconciling_applies_what_is_available_now_and_closes_the_shortfall(): void
    {
        $this->opening($this->central, '1');
        $this->sale('4', '2026-10-05');
        $this->sync();
        $shortfall = InventoryShortfall::sole();
        $this->assertSame('3', Quantity::display(Quantity::units($shortfall->missing)));

        // Todavía no hay mercancía: nada que aplicar.
        $r = app(SalesInventorySync::class)->reconcile($shortfall, $this->admin);
        $this->assertSame(['0', '3', false], [$r['applied'], $r['missing'], $r['resolved']]);

        // Entra mercancía: se aplica lo disponible, en dos pasos.
        app(InventoryLedger::class)->record($this->a->id, $this->central->id, $this->oil, InventoryMovement::ENTRY, '2', null, Carbon::parse('2026-10-10'), $this->admin, '30');
        $r = app(SalesInventorySync::class)->reconcile($shortfall->fresh(), $this->admin);
        $this->assertSame(['2', '1', false], [$r['applied'], $r['missing'], $r['resolved']]);
        app(InventoryLedger::class)->record($this->a->id, $this->central->id, $this->oil, InventoryMovement::ENTRY, '5', null, Carbon::parse('2026-10-11'), $this->admin, '75');
        $r = app(SalesInventorySync::class)->reconcile($shortfall->fresh(), $this->admin);
        $this->assertSame(['1', '0', true], [$r['applied'], $r['missing'], $r['resolved']]);

        $this->assertSame('4', $this->stock($this->central));
        $this->assertNotNull($shortfall->fresh()->resolved_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.shortfall_reconciled']);
        $this->assertSame(0, app(SalesInventorySync::class)->run()['shortfalls']);     // no vuelve a crearse
    }

    public function test_the_api_lists_shortfalls_and_only_managers_reconcile(): void
    {
        $this->opening($this->central, '1');
        $this->sale('4', '2026-10-05');
        $this->sync();

        $list = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/inventory/shortfalls')->assertOk();
        $list->assertJsonPath('meta.open_total', 1)->assertJsonPath('data.0.document', 'F-1')->assertJsonPath('data.0.missing', '3')->assertJsonPath('data.0.branch', 'Central');
        $this->getJson('/api/v1/inventory/stock')->assertJsonPath('meta.open_shortfalls', 1);
        $id = $list->json('data.0.id');

        $this->postJson("/api/v1/inventory/shortfalls/$id/reconcile")->assertForbidden();
        $this->postJson('/api/v1/inventory/sync-sales')->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/inventory/shortfalls/$id/reconcile")->assertOk()->assertJsonPath('data.resolved', false);
        $this->postJson('/api/v1/inventory/sync-sales')->assertStatus(202);
        $this->assertCount(0, $this->getJson('/api/v1/inventory/shortfalls?status=resolved')->json('data'));
    }

    public function test_loading_the_opening_inventory_discounts_the_sales_already_loaded(): void
    {
        $this->sale('3', '2026-10-05');
        $this->assertSame(0, $this->sync()['branches']);

        // Un inventario inicial posterior dispara la sincronización (en pruebas la cola es síncrona).
        SyncSalesInventoryJob::dispatchSync(null, $this->central->id);
        $this->assertSame(0, InventoryMovement::count());
        $this->opening($this->central, '10');
        SyncSalesInventoryJob::dispatchSync(null, $this->central->id);
        $this->assertSame('7', $this->stock($this->central));
    }
}
