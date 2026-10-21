<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryRotationTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $central;

    private Branch $norte;

    private User $admin;

    private User $viewer;

    private InventoryLedger $ledger;

    /** @var array<string, Product> */
    private array $p = [];

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
        $this->ledger = app(InventoryLedger::class);
        foreach (['A' => 'Bajo', 'B' => 'Sobrante', 'C' => 'Parado', 'D' => 'Agotado', 'E' => 'Normal', 'F' => 'Viejo'] as $code => $name) {
            $this->p[$code] = Product::create(['company_id' => $this->a->id, 'code' => $code, 'name' => $name, 'base_unit_id' => Unit::where('code', 'unidad')->value('id')]);
        }
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->viewer->role_id, ['permissions' => ['inventory.view']])->assertOk();

        $this->open('A', '100');
        $this->sell('A', '30', '2026-08-01');
        $this->sell('A', '60', '2026-10-10');                       // vendió 90 en la ventana → demanda 1/día; quedan 10
        $this->open('B', '500');
        $this->sell('B', '9', '2026-10-10');                        // demanda 0,1/día; quedan 491
        $this->open('C', '20');                                     // sin ventas
        $this->open('D', '5');
        $this->sell('D', '5', '2026-10-01');                        // agotado con demanda
        $this->open('E', '120');
        $this->sell('E', '90', '2026-10-05');                       // demanda 1/día; quedan 30 (entre mínimo 22 y máximo 52)
        $this->open('F', '10');
        $this->sell('F', '4', '2026-06-01');                        // venta anterior a la ventana y a 60 días
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function open(string $code, string $qty): void
    {
        $this->ledger->record($this->a->id, $this->central->id, $this->p[$code], InventoryMovement::OPENING, $qty, null, Carbon::parse('2026-05-15'), $this->admin, (string) ((int) $qty * 10));
    }

    private function sell(string $code, string $qty, string $on): void
    {
        $this->ledger->record($this->a->id, $this->central->id, $this->p[$code], InventoryMovement::SALE, $qty, null, Carbon::parse($on), null);
    }

    private function report(string $query = '', ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->getJson('/api/v1/inventory/rotation'.$query)->assertOk();
    }

    public function test_every_product_gets_the_agreed_status_and_figures(): void
    {
        $r = $this->report();
        $rows = collect($r->json('data'))->keyBy(fn ($x) => $x['product']['code']);

        $this->assertSame(['out_of_stock' => 1, 'below_min' => 1, 'no_rotation' => 2, 'above_max' => 1, 'ok' => 1], $r->json('meta.counts'));

        $a = $rows['A'];
        $this->assertSame(['below_min', '10', '90', '1', '10', '22', '52', '42'], [$a['status'], $a['stock'], $a['sold'], $a['daily_demand'], $a['coverage_days'], $a['min'], $a['max'], $a['suggested']]);
        $this->assertSame('1.64', $a['rotation']);                    // 90 ÷ ((100 + 10) ÷ 2)
        $this->assertSame(['2026-10-10', 10], [$a['last_sale'], $a['days_since_sale']]);
        $this->assertSame(['ok', '30', '30'], [$rows['E']['status'], $rows['E']['stock'], $rows['E']['coverage_days']]);
        $this->assertSame(['above_max', '491'], [$rows['B']['status'], $rows['B']['stock']]);
        $this->assertSame(['out_of_stock', '0'], [$rows['D']['status'], $rows['D']['stock']]);
        $this->assertSame(['no_rotation', null, null], [$rows['C']['status'], $rows['C']['last_sale'], $rows['C']['coverage_days']]);
        $this->assertSame(['no_rotation', '0'], [$rows['F']['status'], $rows['F']['sold']]);          // vendió antes de la ventana
        $this->assertSame([90, 60, 22, 30], [$r->json('meta.params.demand_days'), $r->json('meta.params.no_movement_days'), $r->json('meta.params.reorder_days') + $r->json('meta.params.safety_days'), $r->json('meta.params.cycle_days')]);
    }

    public function test_the_list_is_ordered_by_urgency_and_filters_by_status_branch_and_text(): void
    {
        $order = array_column(array_column($this->report()->json('data'), 'product'), 'code');
        $this->assertSame(['D', 'A'], array_slice($order, 0, 2));                                   // agotado, luego bajo el mínimo
        $this->assertCount(6, $order);

        $this->assertSame(['A'], array_column(array_column($this->report('?status=below_min')->json('data'), 'product'), 'code'));
        $this->assertEqualsCanonicalizing(['C', 'F'], array_column(array_column($this->report('?status=no_rotation')->json('data'), 'product'), 'code'));
        $this->assertSame(['E'], array_column(array_column($this->report('?q=norm')->json('data'), 'product'), 'code'));
        $this->assertSame([], $this->report('?branch_id='.$this->norte->id)->json('data'));
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/inventory/rotation?status=inventado')->assertUnprocessable();
    }

    public function test_costs_only_for_those_who_can_see_them_and_the_user_scope_applies(): void
    {
        $this->assertArrayHasKey('value', $this->report()->json('data.0'));
        $this->assertArrayNotHasKey('value', $this->report('', $this->viewer)->json('data.0'));

        // Un usuario limitado a la sucursal Norte (donde no hay nada) no ve las existencias de Central.
        $limited = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $limited->companies()->attach($this->a->id);
        $limited->branches()->attach($this->norte->id);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$limited->role_id, ['permissions' => ['inventory.view']])->assertOk();
        $this->assertSame([], $this->report('', $limited)->json('data'));
    }

    public function test_the_csv_export_is_audited_and_neutralizes_formulas(): void
    {
        $this->p['C']->forceFill(['name' => '=cmd'])->save();
        $csv = $this->actingAs($this->admin, 'sanctum')->get('/api/v1/inventory/rotation/export?status=no_rotation')->assertOk()->streamedContent();

        $this->assertStringContainsString('Cobertura (días)', $csv);
        $this->assertStringContainsString("'=cmd", $csv);
        $this->assertStringContainsString('Sin rotación', $csv);
        $this->assertStringContainsString('Valor', $csv);
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.rotation_exported']);

        $viewer = $this->actingAs($this->viewer, 'sanctum')->get('/api/v1/inventory/rotation/export')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('Valor', $viewer);
        $this->actingAs(User::factory()->role(Role::VENDEDOR)->create(), 'sanctum')->getJson('/api/v1/inventory/rotation')->assertForbidden();
    }
}
