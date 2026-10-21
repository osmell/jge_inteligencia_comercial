<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Inventory\Quantity;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
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
use Illuminate\Support\Facades\Storage;
use Tests\Support\Xlsx;
use Tests\TestCase;

class InventoryEntriesImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Fecha', 'Documento', 'Proveedor', 'Sucursal', 'Compañia', 'Codigo Producto', 'Unidad Medida', 'Cantidad', 'Costo', 'Descuento', 'Sub Total', 'Iva', 'Total', 'Observaciones'];

    private User $admin;

    private Company $alfa;

    private Branch $central;

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
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->oil = Product::create(['company_id' => $this->alfa->id, 'code' => '100', 'name' => 'Aceite', 'base_unit_id' => $this->unit('galon')]);
        ProductUnit::create(['product_id' => $this->oil->id, 'unit_id' => $this->unit('cubeta'), 'factor_num' => 5, 'factor_den' => 1]);
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

    /** 2 cubetas a 60 con 10 de descuento y 15 % de IVA: subtotal 110, IVA 16,5, total 126,5. */
    private function line(array $o = []): array
    {
        $d = [
            'Fecha' => '2026-10-05', 'Documento' => 'C-100', 'Proveedor' => 'Lubricantes SA', 'Sucursal' => 'CEN', 'Compañia' => 'ALFA', 'Codigo Producto' => '100', 'Unidad Medida' => 'Cubeta',
            'Cantidad' => 2, 'Costo' => 60, 'Descuento' => 10, 'Sub Total' => 110, 'Iva' => 16.5, 'Total' => 126.5, 'Observaciones' => 'Compra mensual', ...$o,
        ];

        return array_map(fn ($h) => $d[$h], self::HEADER);
    }

    private function validate(array $lines): ImportBatch
    {
        $path = Xlsx::make([self::HEADER, ...$lines]);
        $stored = 'inv/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));
        $batch = ImportBatch::factory()->create(['type' => 'inventory_entries', 'status' => ImportStatus::Pending, 'stored_path' => $stored, 'file_hash' => hash_file('sha256', $path), 'user_id' => $this->admin->id]);
        app(ImportRunner::class)->validate($batch);

        return $batch->fresh();
    }

    private function imported(array $lines): ImportBatch
    {
        $batch = $this->validate($lines);
        $this->assertSame(ImportStatus::Validated, $batch->status, json_encode($batch->errors()->pluck('message')));
        app(ImportRunner::class)->import($batch);

        return $batch->fresh();
    }

    private function codes(ImportBatch $batch): array
    {
        return $batch->errors()->where('severity', 'error')->pluck('code')->unique()->values()->all();
    }

    private function opening(string $cutoff = '2026-09-30'): void
    {
        app(InventoryLedger::class)->record($this->alfa->id, $this->central->id, $this->filter, InventoryMovement::OPENING, '3', null, Carbon::parse($cutoff), $this->admin, '30');
    }

    public function test_a_document_is_loaded_with_its_lines_converted_and_valued_without_tax(): void
    {
        $this->opening();
        $batch = $this->imported([
            $this->line(),
            $this->line(['Codigo Producto' => '200', 'Unidad Medida' => 'Unidad', 'Cantidad' => 4, 'Costo' => 12.5, 'Descuento' => null, 'Sub Total' => 50, 'Iva' => null, 'Total' => 50, 'Observaciones' => null]),
        ]);

        $this->assertSame(ImportStatus::Imported, $batch->status);
        $receipt = InventoryReceipt::sole();
        $this->assertSame(['Lubricantes SA', 'C-100', '2026-10-05', '160.0000', '10.0000', '16.5000', '176.5000', 2, $this->central->id], [
            $receipt->supplier, $receipt->document, $receipt->received_on->toDateString(), $receipt->subtotal, $receipt->discount_total, $receipt->tax_total, $receipt->total, $receipt->lines_count, $receipt->branch_id,
        ]);

        $oil = InventoryMovement::where('product_id', $this->oil->id)->sole();
        $this->assertSame(['entry', '10.00000000', '2.00000000', $this->unit('cubeta'), '110.0000', $receipt->id, 'Compra mensual'], [$oil->type, $oil->quantity, $oil->entered_quantity, $oil->unit_id, $oil->value, $oil->receipt_id, $oil->note]);
        $this->assertSame('Lubricantes SA · C-100', $oil->reference);
        $ledger = app(InventoryLedger::class);
        $this->assertSame([Quantity::units('10'), 1100000], $ledger->balance($this->alfa->id, $this->central->id, $this->oil->id));
        $this->assertSame([Quantity::units('7'), 800000], $ledger->balance($this->alfa->id, $this->central->id, $this->filter->id));   // 30 + 50
        $this->assertSame([1, 0, 2], [$batch->summary['entries']['new'], $batch->summary['entries']['unchanged'], $batch->summary['entries']['lines']]);
    }

    public function test_a_second_file_repeating_a_document_skips_it_and_a_changed_one_is_rejected(): void
    {
        $this->opening();
        $this->imported([$this->line()]);

        $again = $this->validate([$this->line(), $this->line(['Documento' => 'C-101'])]);
        $this->assertSame(ImportStatus::Validated, $again->status);
        $this->assertSame([1, 1], [$again->documents_duplicate, $again->documents_new]);
        app(ImportRunner::class)->import($again);
        $this->assertSame(2, InventoryReceipt::count());
        $this->assertSame(2, InventoryMovement::where('type', 'entry')->count());

        $changed = $this->validate([$this->line(['Cantidad' => 3, 'Sub Total' => 170, 'Iva' => 25.5, 'Total' => 195.5])]);
        $this->assertSame(ImportStatus::Rejected, $changed->status);
        $this->assertSame(['document_conflict'], $this->codes($changed));
    }

    public function test_the_same_document_number_from_another_supplier_is_another_document(): void
    {
        $this->opening();
        $this->imported([$this->line(), $this->line(['Proveedor' => 'Otro Proveedor'])]);

        $this->assertSame(2, InventoryReceipt::count());
    }

    public function test_amounts_must_add_up_and_are_never_corrected(): void
    {
        $this->opening();
        $batch = $this->validate([
            $this->line(['Sub Total' => 120]),                          // debería ser 2 × 60 − 10 = 110
            $this->line(['Documento' => 'C-2', 'Total' => 130]),        // debería ser 110 + 16,5
            $this->line(['Documento' => 'C-3', 'Descuento' => -1]),
            $this->line(['Documento' => 'C-4', 'Costo' => -60]),
        ]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertEqualsCanonicalizing(['amount_mismatch', 'negative_amount'], $this->codes($batch));
        $this->assertSame(0, InventoryMovement::where('type', 'entry')->count());
    }

    public function test_row_validations(): void
    {
        $this->opening();
        $batch = $this->validate([
            $this->line(['Fecha' => '2026-10-25']),
            $this->line(['Documento' => 'C-2', 'Codigo Producto' => '999']),
            $this->line(['Documento' => 'C-3', 'Unidad Medida' => 'quintal']),
            $this->line(['Documento' => 'C-4', 'Codigo Producto' => '200', 'Unidad Medida' => 'Galón', 'Costo' => 60]),    // sin equivalencia
            $this->line(['Documento' => 'C-5', 'Cantidad' => -2, 'Sub Total' => -130, 'Iva' => 0, 'Total' => -130]),
            $this->line(['Documento' => 'C-6', 'Sucursal' => 'XXX']),
            $this->line(['Documento' => 'C-7', 'Proveedor' => null]),
        ]);

        $this->assertEqualsCanonicalizing(['future_date', 'unknown_product', 'unknown_unit', 'no_equivalence', 'negative_quantity', 'unknown_branch', 'required', 'negative_amount'], $this->codes($batch));
    }

    public function test_a_product_cannot_repeat_in_the_same_unit_within_a_document(): void
    {
        $this->opening();
        $batch = $this->validate([$this->line(), $this->line()]);

        $this->assertSame(['duplicate_line'], $this->codes($batch));
    }

    public function test_documents_dated_on_or_before_the_cutoff_are_skipped_with_a_warning(): void
    {
        $this->opening('2026-09-30');
        $batch = $this->imported([$this->line(['Fecha' => '2026-09-30', 'Documento' => 'VIEJO']), $this->line(['Fecha' => '2026-10-01', 'Documento' => 'NUEVO'])]);

        $this->assertSame(['NUEVO'], InventoryReceipt::pluck('document')->all());
        $this->assertSame(1, $batch->summary['entries']['skipped_before_cutoff']);
        $this->assertSame(1, $batch->errors()->where('code', 'before_cutoff')->count());
    }

    public function test_a_branch_without_opening_accepts_entries_but_warns(): void
    {
        $batch = $this->imported([$this->line()]);

        $this->assertSame(1, InventoryReceipt::count());
        $this->assertSame(['Central'], $batch->summary['entries']['branches_without_opening']);
        $this->assertSame(1, $batch->errors()->where('code', 'branch_without_opening')->count());
    }

    public function test_zero_quantity_lines_are_skipped_with_a_warning(): void
    {
        $this->opening();
        $batch = $this->imported([$this->line(), $this->line(['Codigo Producto' => '200', 'Unidad Medida' => 'Unidad', 'Cantidad' => 0, 'Sub Total' => 0, 'Descuento' => 0, 'Iva' => 0, 'Total' => 0])]);

        $this->assertSame(1, InventoryMovement::where('type', 'entry')->count());
        $this->assertSame(1, InventoryReceipt::sole()->lines_count);
        $this->assertSame(1, $batch->errors()->where('code', 'zero_quantity')->count());
    }
}
