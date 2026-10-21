<?php

namespace Tests\Feature;

use App\Imports\Decimal;
use App\Inventory\Quantity;
use App\Models\Branch;
use App\Models\Company;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class InventoryLedgerTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $branch;

    private Product $oil;

    private User $admin;

    private InventoryLedger $ledger;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->ledger = app(InventoryLedger::class);
        $this->oil = $this->product('ACE-1', 'galon', ['cubeta' => '5', '1_16' => '0.0625', '1_64' => '0.015625']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param  array<string, string>  $equivalences */
    private function product(string $code, ?string $base, array $equivalences = []): Product
    {
        $product = Product::create(['company_id' => $this->a->id, 'code' => $code, 'name' => "Producto $code", 'base_unit_id' => $base ? $this->unit($base) : null]);
        foreach ($equivalences as $unit => $factor) {
            [$num, $den] = Quantity::factor($factor);
            ProductUnit::create(['product_id' => $product->id, 'unit_id' => $this->unit($unit), 'factor_num' => $num, 'factor_den' => $den]);
        }

        return $product;
    }

    private function unit(string $code): int
    {
        return (int) Unit::where('code', $code)->value('id');
    }

    private function in(Product $p, string $qty, string $value, ?string $unit = null, string $on = '2026-10-01', ?string $key = null): InventoryMovement
    {
        return $this->ledger->record($this->a->id, $this->branch->id, $p, InventoryMovement::ENTRY, $qty, $unit ? $this->unit($unit) : null, Carbon::parse($on), $this->admin, $value, sourceKey: $key);
    }

    private function out(Product $p, string $qty, ?string $unit = null, string $type = InventoryMovement::SALE, string $on = '2026-10-10'): InventoryMovement
    {
        return $this->ledger->record($this->a->id, $this->branch->id, $p, $type, $qty, $unit ? $this->unit($unit) : null, Carbon::parse($on), $this->admin);
    }

    /** @return array{0: string, 1: string} */
    private function balance(Product $p): array
    {
        [$q, $v] = $this->ledger->balance($this->a->id, $this->branch->id, $p->id);

        return [Quantity::display($q), Decimal::format($v)];
    }

    public function test_quantities_use_exact_integer_arithmetic(): void
    {
        $this->assertSame(97656, Quantity::units('0.00097656'));
        $this->assertSame(97656, Quantity::units('0.000976563'));   // 9.º decimal 3 → no sube
        $this->assertSame(97657, Quantity::units('0.000976565'));   // redondeo mitad hacia arriba
        $this->assertSame('0.00097656', Quantity::display(97656));
        $this->assertSame('12.5', Quantity::display(Quantity::units('12.50')));
        $this->assertSame('0', Quantity::display(0));
        $this->assertSame(Quantity::units('5'), Quantity::convert(Quantity::units('1'), 5, 1));
        $this->assertSame(Quantity::units('0.0625'), Quantity::convert(Quantity::units('1'), 1, 16));
        $this->assertSame(Quantity::units('0.1875'), Quantity::convert(Quantity::units('3'), 1, 16));
        $this->assertSame([1, 16], Quantity::factor('0.0625'));
        $this->assertSame([55, 1], Quantity::factor('55.000000'));
        $this->assertSame('0.015625', Quantity::factorText(...Quantity::factor('0.015625')));
        $this->assertSame('55', Quantity::factorText(55, 1));
        $this->assertSame('3.785', Quantity::factorText(...Quantity::factor('3.785')));
        $this->expectException(\InvalidArgumentException::class);
        Quantity::factor('0');
    }

    public function test_an_entry_in_an_alternate_unit_is_converted_to_the_base_unit(): void
    {
        $m = $this->in($this->oil, '2', '120', 'cubeta');   // 2 cubetas × 5 = 10 galones

        $this->assertSame('10.00000000', $m->quantity);
        $this->assertSame('2.00000000', $m->entered_quantity);
        $this->assertSame(['10', '120.0000'], $this->balance($this->oil));
    }

    public function test_sales_style_fractions_are_kept_exactly(): void
    {
        $this->in($this->oil, '10', '100');
        $this->out($this->oil, '3', '1_16');                // 3/16 de galón
        $this->out($this->oil, '0.00097656');

        $this->assertSame(['9.81152344', '98.1152'], $this->balance($this->oil));
    }

    public function test_outflows_use_the_weighted_average_cost(): void
    {
        $this->in($this->oil, '10', '100');                 // promedio 10
        $this->in($this->oil, '10', '140', on: '2026-10-02'); // promedio (100+140)/20 = 12
        $out = $this->out($this->oil, '5');

        $this->assertSame('-60.0000', $out->value);
        $this->assertSame(['15', '180.0000'], $this->balance($this->oil));

        $this->out($this->oil, '15');                       // sale todo: queda exactamente en cero
        $this->assertSame(['0', '0.0000'], $this->balance($this->oil));
    }

    public function test_stock_can_never_go_negative(): void
    {
        $this->in($this->oil, '4', '40');

        try {
            $this->out($this->oil, '4.00000001');
            $this->fail('Debió rechazar la salida.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('No se permiten existencias negativas', $e->errors()['quantity'][0]);
        }
        $this->assertSame(['4', '40.0000'], $this->balance($this->oil));
        $this->assertSame(1, InventoryMovement::count());
    }

    public function test_a_source_key_makes_repeating_a_movement_harmless(): void
    {
        $first = $this->in($this->oil, '10', '100', key: 'entrada:D-1:ACE-1');
        $again = $this->in($this->oil, '10', '100', key: 'entrada:D-1:ACE-1');

        $this->assertTrue($first->wasRecentlyCreated);
        $this->assertSame($first->id, $again->id);
        $this->assertFalse($again->wasRecentlyCreated);
        $this->assertSame(['10', '100.0000'], $this->balance($this->oil));
    }

    public function test_a_reversal_restores_the_balance_and_cannot_repeat_or_go_negative(): void
    {
        $entry = $this->in($this->oil, '10', '100');
        $sale = $this->out($this->oil, '4');

        // Revertir la entrada dejaría -4: se rechaza.
        try {
            $this->ledger->reverse($entry, $this->admin, 'Error');
            $this->fail('Debió rechazar la reversa.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('movement', $e->errors());
        }

        $reversal = $this->ledger->reverse($sale, $this->admin, 'Venta anulada');
        $this->assertSame([InventoryMovement::REVERSAL, $sale->id, '4.00000000', '40.0000'], [$reversal->type, $reversal->reverses_id, $reversal->quantity, $reversal->value]);
        $this->assertSame(['10', '100.0000'], $this->balance($this->oil));

        $this->expectException(ValidationException::class);
        $this->ledger->reverse($sale, $this->admin, 'otra vez');
    }

    public function test_reversals_need_a_note_and_are_not_reversible(): void
    {
        $entry = $this->in($this->oil, '10', '100');
        $reversal = $this->ledger->reverse($entry, $this->admin, 'Error');

        try {
            $this->ledger->reverse($reversal, $this->admin, 'x');
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('movement', $e->errors());
        }
        $this->expectException(ValidationException::class);
        $this->ledger->reverse($this->in($this->oil, '1', '1', on: '2026-10-03'), $this->admin, '  ');
    }

    public function test_validations(): void
    {
        $noBase = $this->product('SIN-BASE', null);
        $other = $this->product('OTRA', 'unidad');
        $rejected = function (callable $call, string $field) {
            try {
                $call();
                $this->fail("Debió rechazar ($field).");
            } catch (ValidationException $e) {
                $this->assertArrayHasKey($field, $e->errors());
            }
        };

        $rejected(fn () => $this->in($noBase, '1', '1'), 'product');
        $rejected(fn () => $this->in($other, '1', '1', 'cubeta'), 'unit');                   // sin equivalencia
        $rejected(fn () => $this->in($this->oil, '0', '1'), 'quantity');
        $rejected(fn () => $this->in($this->oil, '-1', '1'), 'quantity');
        $rejected(fn () => $this->in($this->oil, '10000001', '1'), 'quantity');
        $rejected(fn () => $this->in($this->oil, '1', '-5'), 'value');
        $rejected(fn () => $this->ledger->record($this->a->id, $this->branch->id, $this->oil, InventoryMovement::ENTRY, '1', null, Carbon::parse('2026-10-01'), $this->admin), 'value');
        $rejected(fn () => $this->in($this->oil, '1', '1', on: '2026-10-21'), 'occurred_on');  // futura
        $rejected(fn () => $this->ledger->record($this->a->id, Branch::factory()->create(['company_id' => Company::factory()->create()->id])->id, $this->oil, InventoryMovement::ENTRY, '1', null, Carbon::parse('2026-10-01'), $this->admin, '1'), 'branch');
        $rejected(fn () => $this->ledger->record($this->a->id, $this->branch->id, $this->oil, 'inventado', '1', null, Carbon::parse('2026-10-01')), 'type');
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_other_inflows_are_valued_at_the_average_when_no_value_is_given(): void
    {
        $this->in($this->oil, '10', '100');
        $m = $this->ledger->record($this->a->id, $this->branch->id, $this->oil, InventoryMovement::RETURN_IN, '2', null, Carbon::parse('2026-10-05'), $this->admin);

        $this->assertSame('20.0000', $m->value);
        $this->assertSame(['12', '120.0000'], $this->balance($this->oil));
        $this->expectException(ValidationException::class);   // sin existencias no hay promedio
        $this->ledger->record($this->a->id, $this->branch->id, $this->product('VACIO', 'unidad'), InventoryMovement::ADJUSTMENT_IN, '1', null, Carbon::parse('2026-10-05'), $this->admin);
    }

    public function test_the_balance_always_equals_the_sum_of_the_movements_and_movements_are_immutable(): void
    {
        $this->in($this->oil, '7.5', '75.5');
        $this->out($this->oil, '2.25');
        $this->out($this->oil, '1', '1_64');
        $this->ledger->reverse(InventoryMovement::where('type', 'sale')->orderBy('id')->first(), $this->admin, 'Anulada');

        $sum = InventoryMovement::all();
        $this->assertSame(
            [$sum->sum(fn ($m) => Quantity::units((string) $m->quantity)), $sum->sum(fn ($m) => Decimal::units((string) $m->value))],
            $this->ledger->balance($this->a->id, $this->branch->id, $this->oil->id),
        );
        $this->assertSame(1, DB::table('inventory_balances')->count());

        $this->expectException(LogicException::class);
        InventoryMovement::first()->update(['note' => 'x']);
    }

    public function test_user_movements_are_audited_but_imports_can_skip_it(): void
    {
        $this->in($this->oil, '1', '1');
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.movement_entry']);

        $before = DB::table('audit_logs')->count();
        $this->ledger->record($this->a->id, $this->branch->id, $this->oil, InventoryMovement::ENTRY, '1', null, Carbon::parse('2026-10-02'), $this->admin, '1', audit: false);
        $this->assertSame($before, DB::table('audit_logs')->count());
    }
}
