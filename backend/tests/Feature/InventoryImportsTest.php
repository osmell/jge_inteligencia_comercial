<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Inventory\Quantity;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
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
use Illuminate\Support\Facades\Storage;
use Tests\Support\Xlsx;
use Tests\TestCase;

class InventoryImportsTest extends TestCase
{
    use RefreshDatabase;

    private const OPENING = ['Fecha Corte', 'Codigo Producto', 'Unidad de Medida', 'Cantidad', 'Costo', 'Sucursal', 'Compañia'];

    private const UNITS = ['Compañia', 'Codigo Producto', 'Unidad Base', 'Unidad', 'Factor'];

    private User $admin;

    private Company $alfa;

    private Branch $central;

    private Branch $norte;

    private Product $oil;

    private Product $filter;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        Storage::fake('imports');
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['code' => 'ALFA', 'name' => 'Compañía Alfa']);
        $this->central = Branch::factory()->create(['company_id' => $this->alfa->id, 'code' => 'CEN', 'name' => 'Central']);
        $this->norte = Branch::factory()->create(['company_id' => $this->alfa->id, 'code' => 'NOR', 'name' => 'Norte']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->oil = Product::create(['company_id' => $this->alfa->id, 'code' => '100', 'name' => 'Aceite', 'base_unit_id' => $this->unit('galon')]);
        foreach (['cubeta' => [5, 1], '1_16' => [1, 16]] as $unit => [$num, $den]) {
            ProductUnit::create(['product_id' => $this->oil->id, 'unit_id' => $this->unit($unit), 'factor_num' => $num, 'factor_den' => $den]);
        }
        $this->filter = Product::create(['company_id' => $this->alfa->id, 'code' => '200', 'name' => 'Filtro', 'base_unit_id' => $this->unit('unidad')]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function unit(string $code): int
    {
        return (int) Unit::where('code', $code)->value('id');
    }

    private function opening(array $o = []): array
    {
        $d = ['Fecha Corte' => '2026-09-30', 'Codigo Producto' => '100', 'Unidad de Medida' => 'Galón', 'Cantidad' => 10, 'Costo' => 12.5, 'Sucursal' => 'CEN', 'Compañia' => 'ALFA', ...$o];

        return array_map(fn ($h) => $d[$h], self::OPENING);
    }

    private function unitsRow(array $o = []): array
    {
        $d = ['Compañia' => 'ALFA', 'Codigo Producto' => '100', 'Unidad Base' => 'Galón', 'Unidad' => 'Barril', 'Factor' => 55, ...$o];

        return array_map(fn ($h) => $d[$h], self::UNITS);
    }

    private function validate(string $type, array $header, array $rows): ImportBatch
    {
        $path = Xlsx::make([$header, ...$rows]);
        $stored = 'inv/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));
        $batch = ImportBatch::factory()->create(['type' => $type, 'status' => ImportStatus::Pending, 'stored_path' => $stored, 'file_hash' => hash_file('sha256', $path), 'user_id' => $this->admin->id]);
        app(ImportRunner::class)->validate($batch);

        return $batch->fresh();
    }

    private function imported(string $type, array $header, array $rows): ImportBatch
    {
        $batch = $this->validate($type, $header, $rows);
        $this->assertSame(ImportStatus::Validated, $batch->status, json_encode($batch->errors()->pluck('message')));
        app(ImportRunner::class)->import($batch);

        return $batch->fresh();
    }

    private function codes(ImportBatch $batch): array
    {
        return $batch->errors()->where('severity', 'error')->pluck('code')->unique()->values()->all();
    }

    public function test_extending_a_quantity_by_a_cost_is_exact(): void
    {
        $this->assertSame(1250000, Quantity::extend(Quantity::units('10'), 125000));                  // 10 × 12,5 = 125,0000
        $this->assertSame(20, Quantity::extend(Quantity::units('0.00097656'), 20000));                // 0,00097656 × 2 = 0,00195312 → 0,0020 (4 decimales)
        $this->assertSame(99_999_999_999_999_900, Quantity::extend(Quantity::units('9999999.99999999'), 10_000_000_000));
        $this->assertSame(0, Quantity::extend(0, 125000));
    }

    public function test_the_opening_inventory_is_loaded_in_the_entered_unit_and_converted(): void
    {
        $batch = $this->imported('inventory_opening', self::OPENING, [
            $this->opening(),                                                                           // 10 galones a 12,5
            $this->opening(['Unidad de Medida' => 'cubeta', 'Cantidad' => 2, 'Costo' => 60, 'Codigo Producto' => '100', 'Sucursal' => 'NOR']),   // otra sucursal, 2 cubetas a 60
            $this->opening(['Codigo Producto' => '200', 'Unidad de Medida' => 'Unidad', 'Cantidad' => 7, 'Costo' => 3.25]),
        ]);

        $this->assertSame(ImportStatus::Imported, $batch->status);
        $ledger = app(InventoryLedger::class);
        $this->assertSame([Quantity::units('10'), 1250000], $ledger->balance($this->alfa->id, $this->central->id, $this->oil->id));
        $this->assertSame([Quantity::units('10'), 1200000], $ledger->balance($this->alfa->id, $this->norte->id, $this->oil->id));   // 2 cubetas = 10 galones; 2 × 60
        $m = InventoryMovement::where('branch_id', $this->norte->id)->sole();
        $this->assertSame(['opening', '2.00000000', $this->unit('cubeta'), '2026-09-30', $batch->id], [$m->type, $m->entered_quantity, $m->unit_id, $m->occurred_on->toDateString(), $m->import_batch_id]);
        $this->assertSame(3, InventoryMovement::count());
        $this->assertSame(3, $batch->summary['inventory']['new']);
        $this->assertEqualsCanonicalizing([2, 1], array_column($batch->summary['inventory']['branches'], 'products'));
    }

    public function test_loading_the_same_file_again_does_not_duplicate_and_a_different_one_is_rejected(): void
    {
        $this->imported('inventory_opening', self::OPENING, [$this->opening()]);

        // Otro archivo que repite la fila ya cargada y agrega un producto: la repetida se omite.
        $again = $this->validate('inventory_opening', self::OPENING, [$this->opening(), $this->opening(['Codigo Producto' => '200', 'Unidad de Medida' => 'Unidad'])]);
        $this->assertSame(ImportStatus::Validated, $again->status);
        $this->assertSame([1, 1], [$again->documents_duplicate, $again->documents_new]);
        app(ImportRunner::class)->import($again);
        $this->assertSame(2, InventoryMovement::count());

        $changed = $this->validate('inventory_opening', self::OPENING, [$this->opening(['Cantidad' => 11])]);
        $this->assertSame(ImportStatus::Rejected, $changed->status);
        $this->assertSame(['opening_exists'], $this->codes($changed));
    }

    public function test_row_validations(): void
    {
        $rows = [
            $this->opening(['Fecha Corte' => '2026-10-25']),                                    // futura
            $this->opening(['Codigo Producto' => '999']),                                         // no existe
            $this->opening(['Unidad de Medida' => 'quintal']),                                    // unidad desconocida
            $this->opening(['Codigo Producto' => '200', 'Unidad de Medida' => 'Galón']),          // sin equivalencia
            $this->opening(['Cantidad' => -1]),
            $this->opening(['Costo' => -2]),
            $this->opening(['Sucursal' => 'XXX']),
            $this->opening(['Cantidad' => 20000000]),
        ];
        $batch = $this->validate('inventory_opening', self::OPENING, $rows);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertEqualsCanonicalizing(['future_cutoff', 'unknown_product', 'unknown_unit', 'no_equivalence', 'negative_quantity', 'negative_cost', 'unknown_branch', 'quantity_too_large'], $this->codes($batch));
        $this->assertSame(0, InventoryMovement::count());
    }

    public function test_a_product_without_base_unit_and_mixed_cutoff_dates_are_rejected(): void
    {
        Product::create(['company_id' => $this->alfa->id, 'code' => '300', 'name' => 'Sin base']);
        $batch = $this->validate('inventory_opening', self::OPENING, [
            $this->opening(['Codigo Producto' => '300']),
            $this->opening(['Fecha Corte' => '2026-09-29', 'Codigo Producto' => '200', 'Unidad de Medida' => 'Unidad']),
        ]);

        $this->assertEqualsCanonicalizing(['no_base_unit', 'cutoff_mismatch'], $this->codes($batch));
    }

    public function test_a_cutoff_that_would_count_earlier_movements_twice_is_rejected(): void
    {
        app(InventoryLedger::class)->record($this->alfa->id, $this->central->id, $this->filter, InventoryMovement::ENTRY, '5', null, Carbon::parse('2026-09-20'), $this->admin, '50');

        $batch = $this->validate('inventory_opening', self::OPENING, [$this->opening()]);          // corte 2026-09-30 ≥ 2026-09-20
        $this->assertSame(['movements_before_cutoff'], $this->codes($batch));

        $ok = $this->validate('inventory_opening', self::OPENING, [$this->opening(['Fecha Corte' => '2026-09-19'])]);
        $this->assertSame(ImportStatus::Validated, $ok->status);
    }

    public function test_zero_quantities_are_skipped_with_a_warning(): void
    {
        $batch = $this->imported('inventory_opening', self::OPENING, [$this->opening(), $this->opening(['Codigo Producto' => '200', 'Unidad de Medida' => 'Unidad', 'Cantidad' => 0])]);

        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(1, $batch->errors()->where('code', 'zero_quantity')->count());
    }

    public function test_the_same_product_may_come_in_two_units_but_not_twice_in_one(): void
    {
        $this->imported('inventory_opening', self::OPENING, [$this->opening(), $this->opening(['Unidad de Medida' => '1/16', 'Cantidad' => 8, 'Costo' => 0.8])]);
        $this->assertSame([Quantity::units('10.5'), 1250000 + 64000], app(InventoryLedger::class)->balance($this->alfa->id, $this->central->id, $this->oil->id));

        $dup = $this->validate('inventory_opening', self::OPENING, [$this->opening(['Codigo Producto' => '200', 'Unidad de Medida' => 'Unidad']), $this->opening(['Codigo Producto' => '200', 'Unidad de Medida' => 'Unidad', 'Cantidad' => 3])]);
        $this->assertSame(['inconsistent_group'], $this->codes($dup));
    }

    public function test_the_units_file_sets_the_base_and_the_equivalences(): void
    {
        $oil2 = Product::create(['company_id' => $this->alfa->id, 'code' => '400', 'name' => 'Lubricante']);
        $batch = $this->imported('product_units', self::UNITS, [
            $this->unitsRow(['Codigo Producto' => '400']),                                                               // barril = 55 galones
            $this->unitsRow(['Codigo Producto' => '400', 'Unidad' => 'Cubeta', 'Factor' => 5]),
            $this->unitsRow(['Codigo Producto' => '400', 'Unidad' => '1/16', 'Factor' => '0.0625']),
            $this->unitsRow(['Codigo Producto' => '200', 'Unidad Base' => 'Unidad', 'Unidad' => null, 'Factor' => null]),   // ya tenía esa base: sin cambios
        ]);

        $this->assertSame($this->unit('galon'), $oil2->fresh()->base_unit_id);
        $factors = $oil2->equivalences()->get()->mapWithKeys(fn ($e) => [Unit::find($e->unit_id)->code => $e->factorText()])->all();
        $this->assertEquals(['barril' => '55', 'cubeta' => '5', '1_16' => '0.0625'], $factors);
        $this->assertSame(['bases_set' => 1, 'new' => 3, 'updated' => 0, 'unchanged' => 0], $batch->summary['units']);

        // Repetir el mismo archivo no cambia nada; cambiar un factor actualiza.
        $again = $this->imported('product_units', self::UNITS, [$this->unitsRow(['Codigo Producto' => '400', 'Unidad' => 'Cubeta', 'Factor' => 5]), $this->unitsRow(['Codigo Producto' => '400', 'Unidad' => 'Litro', 'Factor' => '0.264172'])]);
        $this->assertSame([1, 1], [$again->documents_duplicate, $again->documents_new]);
        $changed = $this->imported('product_units', self::UNITS, [$this->unitsRow(['Codigo Producto' => '400', 'Unidad' => 'Cubeta', 'Factor' => 5.5])]);
        $this->assertSame(1, $changed->summary['units']['updated']);
        $this->assertSame('5.5', ProductUnit::where(['product_id' => $oil2->id, 'unit_id' => $this->unit('cubeta')])->sole()->factorText());
        $this->assertSame(4, $oil2->equivalences()->count());                           // no borra las que no menciona
    }

    public function test_the_units_file_validations(): void
    {
        $batch = $this->validate('product_units', self::UNITS, [
            $this->unitsRow(['Codigo Producto' => '999']),                                                          // producto inexistente
            $this->unitsRow(['Unidad Base' => 'quintal']),                                                          // unidad base desconocida
            $this->unitsRow(['Unidad' => 'Cubeta', 'Factor' => null]),                                              // falta factor
            $this->unitsRow(['Unidad' => 'Cubeta', 'Factor' => 0]),
            $this->unitsRow(['Unidad' => 'Cubeta', 'Factor' => '1.12345678']),                                      // más de 6 decimales
            $this->unitsRow(['Unidad' => 'Galón', 'Factor' => 3]),                                                  // la base no lleva factor
            $this->unitsRow(['Unidad Base' => 'Litro', 'Unidad' => 'Cubeta', 'Factor' => 5]),                       // otra base para el mismo producto
            $this->unitsRow(['Compañia' => 'ZZZ']),
        ]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertEqualsCanonicalizing(['unknown_product', 'unknown_unit', 'required', 'invalid_factor', 'too_many_decimals', 'base_factor', 'base_unit_mismatch', 'unknown_company'], $this->codes($batch));
    }

    public function test_the_base_unit_cannot_change_when_the_product_has_movements(): void
    {
        $this->imported('inventory_opening', self::OPENING, [$this->opening()]);

        $batch = $this->validate('product_units', self::UNITS, [$this->unitsRow(['Unidad Base' => 'Litro', 'Unidad' => null, 'Factor' => null])]);
        $this->assertSame(['base_unit_locked'], $this->codes($batch));
        $this->assertSame($this->unit('galon'), $this->oil->fresh()->base_unit_id);
        $this->assertSame(1, DB::table('inventory_movements')->count());
    }
}
