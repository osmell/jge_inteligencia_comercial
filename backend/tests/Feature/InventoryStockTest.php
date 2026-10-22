<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Inventory\Quantity;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryLedger;
use Database\Seeders\DemoInventorySeeder;
use Database\Seeders\DemoSalesSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InventoryStockTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $central;

    private Branch $norte;

    private User $admin;

    private User $viewer;

    private Product $oil;

    private InventoryLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->viewer = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->central = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Central']);
        $this->norte = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Norte']);
        $this->viewer->companies()->attach($this->a->id);
        $this->oil = Product::create(['company_id' => $this->a->id, 'code' => 'ACE-1', 'name' => 'Aceite', 'base_unit_id' => Unit::where('code', 'galon')->value('id')]);
        $this->ledger = app(InventoryLedger::class);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->viewer->role_id, ['permissions' => ['inventory.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function move(Branch $branch, string $type, string $qty, string $on, ?string $value = null): InventoryMovement
    {
        return $this->ledger->record($this->a->id, $branch->id, $this->oil, $type, $qty, null, Carbon::parse($on), $this->admin, $value);
    }

    public function test_stock_lists_balances_and_hides_costs_without_the_permission(): void
    {
        $this->move($this->central, InventoryMovement::OPENING, '10', '2026-09-01', '100');
        $this->move($this->central, InventoryMovement::SALE, '4', '2026-09-10');
        $this->move($this->norte, InventoryMovement::ENTRY, '3', '2026-09-05', '45');

        $viewer = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/inventory/stock')->assertOk();
        $viewer->assertJsonPath('meta.costs_visible', false)->assertJsonPath('meta.totals.products', 2);
        $this->assertArrayNotHasKey('value', $viewer->json('meta.totals'));
        foreach ($viewer->json('data') as $row) {
            $this->assertArrayNotHasKey('value', $row);
            $this->assertArrayNotHasKey('average_cost', $row);
        }
        $this->assertStringNotContainsString('100', json_encode($viewer->json()));

        $admin = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/inventory/stock')->assertOk()->assertJsonPath('meta.costs_visible', true);
        $rows = collect($admin->json('data'))->keyBy('branch');
        $this->assertSame(['6', '60.0000', '10.0000', true], [$rows['Central']['quantity'], $rows['Central']['value'], $rows['Central']['average_cost'], $rows['Central']['reconciled']]);
        // Norte solo tiene una entrada: sin inventario inicial el saldo es parcial.
        $this->assertSame(['3', false], [$rows['Norte']['quantity'], $rows['Norte']['reconciled']]);
        $this->assertSame('105.00', $admin->json('meta.totals.value'));
    }

    public function test_stock_filters_by_branch_text_and_status(): void
    {
        $this->move($this->central, InventoryMovement::OPENING, '5', '2026-09-01', '50');
        $this->move($this->central, InventoryMovement::SALE, '5', '2026-09-02');
        $this->move($this->norte, InventoryMovement::OPENING, '2', '2026-09-01', '20');

        $branches = fn (string $q) => array_column($this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/inventory/stock'.$q)->assertOk()->json('data'), 'branch');
        $this->assertSame(['Norte'], $branches(''));                                        // por defecto, solo con existencias
        $this->assertSame(['Central'], $branches('?status=zero'));
        $this->assertEqualsCanonicalizing(['Central', 'Norte'], $branches('?status=all'));
        $this->assertSame(['Norte'], $branches('?status=all&branch_id='.$this->norte->id));
        $this->assertSame(['Norte'], $branches('?q=ace'));
        $this->assertSame([], $branches('?q=zzz'));
    }

    public function test_a_user_only_sees_the_branches_in_their_scope(): void
    {
        $this->move($this->central, InventoryMovement::OPENING, '5', '2026-09-01', '50');
        $this->move($this->norte, InventoryMovement::OPENING, '2', '2026-09-01', '20');
        $this->viewer->branches()->attach($this->norte->id);

        $this->assertSame(['Norte'], array_column($this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/inventory/stock?status=all')->json('data'), 'branch'));
    }

    public function test_the_kardex_shows_the_running_balance_and_the_opening_before_the_range(): void
    {
        $this->move($this->central, InventoryMovement::OPENING, '10', '2026-09-01', '100');
        $sale = $this->move($this->central, InventoryMovement::SALE, '4', '2026-09-10');
        $this->move($this->central, InventoryMovement::ENTRY, '5', '2026-09-20', '75');
        $this->ledger->reverse($sale, $this->admin, 'Venta anulada');
        $params = fn (string $extra = '') => '?company_id='.$this->a->id.'&branch_id='.$this->central->id.'&product_id='.$this->oil->id.$extra;

        $all = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/inventory/kardex'.$params())->assertOk();
        $this->assertSame(['0', '10', '6', '11', '15'], [$all->json('data.opening.quantity'), ...array_column($all->json('data.rows'), 'balance')]);
        $this->assertSame(['opening', 'sale', 'entry', 'reversal'], array_column($all->json('data.rows'), 'type'));
        $this->assertTrue($all->json('data.rows.1.reversed'));
        $this->assertSame('15', $all->json('data.closing.quantity'));
        $this->assertSame('175.0000', $all->json('data.closing.value'));
        $this->assertTrue($all->json('data.reconciled'));

        $range = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/inventory/kardex'.$params('&from=2026-09-15'))->assertOk();
        $this->assertSame(['6', '11', '15'], [$range->json('data.opening.quantity'), ...array_slice(array_column($range->json('data.rows'), 'balance'), 0, 1), $range->json('data.closing.quantity')]);

        $viewer = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/inventory/kardex'.$params())->assertOk();
        $this->assertArrayNotHasKey('value', $viewer->json('data.rows.0'));
        $this->assertArrayNotHasKey('value', $viewer->json('data.closing'));
    }

    public function test_the_kardex_validates_and_requires_the_permission(): void
    {
        $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/inventory/kardex')->assertUnprocessable()->assertJsonValidationErrors(['company_id', 'branch_id', 'product_id']);
        $this->getJson('/api/v1/inventory/kardex?company_id='.$this->a->id.'&branch_id='.$this->central->id.'&product_id=999')->assertNotFound();
        $this->getJson('/api/v1/inventory/stock?product_id=1')->assertUnprocessable();
        $this->actingAs(User::factory()->role(Role::VENDEDOR)->create(), 'sanctum')->getJson('/api/v1/inventory/stock')->assertForbidden();
    }

    public function test_the_demo_inventory_never_goes_negative_and_is_repeatable(): void
    {
        Carbon::setTestNow('2026-10-19');
        (new DemoSalesSeeder)->scale(0.04)->run();
        $this->seed(DemoInventorySeeder::class);

        $movements = DB::table('inventory_movements')->get();
        $this->assertGreaterThan(0, $movements->where('type', 'sale')->count());
        $this->assertGreaterThan(0, $movements->where('type', 'opening')->count());
        $this->assertGreaterThan(0, $movements->where('type', 'entry')->whereNotNull('unit_id')->count());   // entradas en cubetas convertidas
        $this->assertSame(0, DB::table('inventory_balances')->where('quantity', '<', 0)->count());
        foreach (DB::table('inventory_balances')->get() as $b) {
            $mine = $movements->where('product_id', $b->product_id)->where('branch_id', $b->branch_id);
            $this->assertSame(Quantity::units((string) $b->quantity), $mine->sum(fn ($m) => Quantity::units((string) $m->quantity)));
        }
        $this->assertSame(0, DB::table('inventory_movements')->where('reference', '!=', 'DEMO')->count());

        $count = $movements->count();
        $this->seed(DemoInventorySeeder::class);
        $this->assertSame($count, DB::table('inventory_movements')->count());
    }
}
