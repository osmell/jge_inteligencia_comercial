<?php

namespace Tests\Feature;

use App\Imports\Decimal;
use App\Inventory\Quantity;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryOperationsTest extends TestCase
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
        $this->viewer->companies()->attach($this->a->id);
        $this->central = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Central']);
        $this->norte = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Norte']);
        $this->oil = Product::create(['company_id' => $this->a->id, 'code' => '100', 'name' => 'Aceite', 'base_unit_id' => Unit::where('code', 'galon')->value('id')]);
        ProductUnit::create(['product_id' => $this->oil->id, 'unit_id' => Unit::where('code', 'cubeta')->value('id'), 'factor_num' => 5, 'factor_den' => 1]);
        $this->ledger = app(InventoryLedger::class);
        $this->ledger->record($this->a->id, $this->central->id, $this->oil, InventoryMovement::OPENING, '20', null, Carbon::parse('2026-09-30'), $this->admin, '200');
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->viewer->role_id, ['permissions' => ['inventory.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function balance(Branch $b): array
    {
        [$q, $v] = $this->ledger->balance($this->a->id, $b->id, $this->oil->id);

        return [Quantity::display($q), Decimal::format($v)];
    }

    private function movement(array $o = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/inventory/movements', $o + [
            'type' => 'adjustment_out', 'company_id' => $this->a->id, 'branch_id' => $this->central->id, 'product_id' => $this->oil->id, 'quantity' => '2',
            'occurred_on' => '2026-10-10', 'reason' => 'damage', 'note' => 'Envase roto',
        ]);
    }

    private function transfer(array $o = [])
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/inventory/transfers', $o + [
            'company_id' => $this->a->id, 'from_branch_id' => $this->central->id, 'to_branch_id' => $this->norte->id, 'product_id' => $this->oil->id, 'quantity' => '4', 'occurred_on' => '2026-10-12',
        ]);
    }

    public function test_adjustments_use_a_fixed_reason_list_and_never_go_negative(): void
    {
        $this->movement()->assertCreated();
        $m = InventoryMovement::where('type', 'adjustment_out')->sole();
        $this->assertSame(['damage', '-2.00000000', '-20.0000', 'Envase roto', $this->admin->id], [$m->reason, $m->quantity, $m->value, $m->note, $m->user_id]);
        $this->assertSame(['18', '180.0000'], $this->balance($this->central));
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.movement_adjustment_out']);

        $this->movement(['reason' => 'inventado'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->movement(['reason' => 'other', 'note' => null])->assertUnprocessable()->assertJsonValidationErrors(['note']);
        $this->movement(['quantity' => '19'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $this->movement(['occurred_on' => '2026-10-25'])->assertUnprocessable()->assertJsonValidationErrors(['occurred_on']);
        $this->assertSame(['18', '180.0000'], $this->balance($this->central));
    }

    public function test_entry_adjustments_and_customer_returns_are_valued_at_the_average_or_the_given_cost(): void
    {
        $this->movement(['type' => 'adjustment_in', 'reason' => 'count_error', 'quantity' => '2'])->assertCreated();          // promedio 10 → +20
        $this->assertSame(['22', '220.0000'], $this->balance($this->central));

        // Devolución de cliente en cubetas, con costo propio: 1 cubeta a 60.
        $this->movement(['type' => 'return_in', 'reason' => 'defective', 'quantity' => '1', 'unit_id' => Unit::where('code', 'cubeta')->value('id'), 'unit_cost' => '60', 'note' => null])->assertCreated();
        $m = InventoryMovement::where('type', 'return_in')->sole();
        $this->assertSame(['5.00000000', '60.0000', '1.00000000'], [$m->quantity, $m->value, $m->entered_quantity]);

        $this->movement(['type' => 'adjustment_out', 'unit_cost' => '5'])->assertUnprocessable()->assertJsonValidationErrors(['unit_cost']);
    }

    public function test_supplier_returns_reduce_stock_and_use_the_return_reasons(): void
    {
        $this->movement(['type' => 'supplier_return', 'reason' => 'wrong_order', 'quantity' => '5'])->assertCreated();
        $this->assertSame(['15', '150.0000'], $this->balance($this->central));
        $this->movement(['type' => 'supplier_return', 'reason' => 'damage'])->assertUnprocessable()->assertJsonValidationErrors(['reason']);   // 'damage' es de ajustes
    }

    public function test_a_transfer_moves_stock_and_value_between_branches_as_a_linked_pair(): void
    {
        $r = $this->transfer(['note' => 'Reposición'])->assertCreated();
        $ref = $r->json('data.reference');

        $this->assertSame(['16', '160.0000'], $this->balance($this->central));
        $this->assertSame(['4', '40.0000'], $this->balance($this->norte));
        $legs = InventoryMovement::where('reference', $ref)->orderBy('id')->get();
        $this->assertSame([['transfer_out', '-40.0000'], ['transfer_in', '40.0000']], $legs->map(fn ($m) => [$m->type, $m->value])->all());
        $this->assertSame([$this->central->id, $this->norte->id], $legs->pluck('branch_id')->all());

        $this->transfer(['to_branch_id' => $this->central->id])->assertUnprocessable()->assertJsonValidationErrors(['to_branch_id']);
        $this->transfer(['quantity' => '17'])->assertUnprocessable()->assertJsonValidationErrors(['quantity']);
        $this->assertSame(['16', '160.0000'], $this->balance($this->central));
        $this->assertSame(2, InventoryMovement::whereIn('type', ['transfer_out', 'transfer_in'])->count());   // el rechazado no dejó la salida
    }

    public function test_reversing_a_transfer_reverses_both_legs_or_neither(): void
    {
        $ref = $this->transfer()->json('data.reference');
        $out = InventoryMovement::where(['reference' => $ref, 'type' => 'transfer_out'])->value('id');
        $this->ledger->record($this->a->id, $this->norte->id, $this->oil, InventoryMovement::SALE, '3', null, Carbon::parse('2026-10-13'), null);   // en destino quedan 1

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/inventory/movements/$out/reverse", ['note' => 'Error'])->assertUnprocessable();
        $this->assertSame(['16', '160.0000'], $this->balance($this->central));            // no se revirtió nada
        $this->assertSame(0, InventoryMovement::where('type', 'reversal')->count());

        $this->ledger->record($this->a->id, $this->norte->id, $this->oil, InventoryMovement::ADJUSTMENT_IN, '3', null, Carbon::parse('2026-10-14'), $this->admin);
        $this->postJson("/api/v1/inventory/movements/$out/reverse", ['note' => 'Error'])->assertOk();
        $this->assertSame(['20', '200.0000'], $this->balance($this->central));
        $this->assertSame(2, InventoryMovement::where('type', 'reversal')->count());
        $this->postJson("/api/v1/inventory/movements/$out/reverse", ['note' => 'otra vez'])->assertUnprocessable();
    }

    public function test_an_entry_document_is_reversed_as_a_whole_and_can_then_be_loaded_again(): void
    {
        $receipt = InventoryReceipt::create([
            'company_id' => $this->a->id, 'branch_id' => $this->central->id, 'supplier' => 'Prov', 'supplier_key' => 'prov', 'document' => 'C-1', 'received_on' => '2026-10-05',
            'subtotal' => 100, 'discount_total' => 0, 'tax_total' => 15, 'total' => 115, 'lines_count' => 1, 'content_hash' => 'h', 'active_key' => "{$this->a->id}|prov|C-1", 'created_at' => now(),
        ]);
        $entry = $this->ledger->record($this->a->id, $this->central->id, $this->oil, InventoryMovement::ENTRY, '10', null, Carbon::parse('2026-10-05'), $this->admin, '100', receiptId: $receipt->id);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/inventory/movements/{$entry->id}/reverse", [])->assertUnprocessable();
        $this->postJson("/api/v1/inventory/movements/{$entry->id}/reverse", ['note' => 'Documento mal digitado'])->assertOk();

        $this->assertSame(['20', '200.0000'], $this->balance($this->central));
        $receipt->refresh();
        $this->assertNotNull($receipt->reversed_at);
        $this->assertNull($receipt->active_key);
        $this->postJson("/api/v1/inventory/movements/{$entry->id}/reverse", ['note' => 'otra'])->assertUnprocessable();
    }

    public function test_sales_and_the_opening_inventory_cannot_be_reversed_here(): void
    {
        $sale = $this->ledger->record($this->a->id, $this->central->id, $this->oil, InventoryMovement::SALE, '1', null, Carbon::parse('2026-10-05'), null);
        $opening = InventoryMovement::where('type', 'opening')->value('id');

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/inventory/movements/{$sale->id}/reverse", ['note' => 'x'])->assertUnprocessable();
        $this->postJson("/api/v1/inventory/movements/$opening/reverse", ['note' => 'x'])->assertUnprocessable();
    }

    public function test_the_list_shows_manual_movements_hides_costs_and_marks_what_can_be_reversed(): void
    {
        $this->movement()->assertCreated();
        $id = InventoryMovement::where('type', 'adjustment_out')->value('id');
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/inventory/movements/$id/reverse", ['note' => 'Error'])->assertOk();
        $this->transfer()->assertCreated();

        $admin = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/inventory/movements')->assertOk();
        $rows = collect($admin->json('data'));
        $this->assertEqualsCanonicalizing(['adjustment_out', 'reversal', 'transfer_in', 'transfer_out'], array_values(array_unique($rows->pluck('type')->all())));
        $adj = $rows->firstWhere('id', $id);
        $this->assertSame([true, false, 'damage', 'Central'], [$adj['reversed'], $adj['reversible'], $adj['reason'], $adj['branch']]);
        $this->assertTrue($rows->firstWhere('type', 'transfer_out')['reversible']);
        $this->assertArrayHasKey('value', $adj);

        $viewer = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/inventory/movements')->assertOk();
        $this->assertArrayNotHasKey('value', $viewer->json('data.0'));
        $this->assertSame(['transfer_in'], collect($this->getJson('/api/v1/inventory/movements?type=transfer_in')->json('data'))->pluck('type')->unique()->values()->all());
    }

    public function test_only_inventory_managers_can_capture_or_reverse(): void
    {
        $this->actingAs($this->viewer, 'sanctum');
        $this->postJson('/api/v1/inventory/movements', ['type' => 'adjustment_out'])->assertForbidden();
        $this->postJson('/api/v1/inventory/transfers', [])->assertForbidden();
        $this->postJson('/api/v1/inventory/movements/1/reverse', ['note' => 'x'])->assertForbidden();
    }
}
