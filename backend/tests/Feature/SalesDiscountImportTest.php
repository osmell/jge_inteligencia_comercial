<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Role;
use App\Models\SalesLine;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Xlsx;
use Tests\TestCase;

class SalesDiscountImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = [
        'Fecha', 'Descuento', 'Sub Total', 'Documento', 'Serie', 'Código Cliente', 'Nombre Cliente', 'Código del Producto', 'Und.Vendidas',
        'Nombre del Producto', 'Costo Tot.Vta.', 'Costo Unit.Vta.', 'Precio Unit.Vta.', 'Precio Unit.Normal', 'Total Neto', 'Total Impuesto',
        'Venta + Imp', 'Gran Total', 'Nombre Vendedor', 'Nombre Nivel 2 Inv', 'Nombre Operador', 'Sucursal', 'Compañìa',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
        $this->seed(RolePermissionSeeder::class);
        $alfa = Company::factory()->create(['code' => 'ALFA', 'name' => 'Compañía Alfa']);
        Branch::factory()->create(['company_id' => $alfa->id, 'code' => 'CEN', 'name' => 'Central']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    /** Línea real: 4 × 45 = 180 de subtotal, 18 de descuento → 162 neto (10 % de descuento). */
    private function line(array $o = []): array
    {
        $d = [
            'Fecha' => '2026-02-16', 'Descuento' => 18, 'Sub Total' => 180, 'Documento' => 3088, 'Serie' => 'FC-D', 'Código Cliente' => 3892,
            'Nombre Cliente' => 'LYDIA LUDIC', 'Código del Producto' => 6001, 'Und.Vendidas' => 4, 'Nombre del Producto' => 'DISCO CORTE',
            'Costo Tot.Vta.' => 49.8, 'Costo Unit.Vta.' => 12.45, 'Precio Unit.Vta.' => 40.5, 'Precio Unit.Normal' => 45, 'Total Neto' => 162,
            'Total Impuesto' => 24.3, 'Venta + Imp' => 186.3, 'Gran Total' => 186.3, 'Nombre Vendedor' => 'FERRETERIA JGE',
            'Nombre Nivel 2 Inv' => 'TRUPER', 'Nombre Operador' => 'ANGIE', 'Sucursal' => 'CEN', 'Compañìa' => 'ALFA',
            ...$o,
        ];

        return array_map(fn ($h) => $d[$h], self::HEADER);
    }

    private function validate(array $lines): ImportBatch
    {
        $path = Xlsx::make([self::HEADER, ...$lines]);
        $stored = 'sales/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));
        $batch = ImportBatch::factory()->create(['type' => 'sales', 'status' => ImportStatus::Pending, 'stored_path' => $stored, 'file_hash' => hash_file('sha256', $path), 'user_id' => $this->admin->id]);
        app(ImportRunner::class)->validate($batch);

        return $batch->fresh();
    }

    public function test_discount_and_subtotal_columns_are_recognized_stored_and_summed(): void
    {
        $batch = $this->validate([$this->line(), $this->line(['Descuento' => 0, 'Sub Total' => 100, 'Código del Producto' => 6002, 'Total Neto' => 100, 'Total Impuesto' => 15, 'Venta + Imp' => 115, 'Gran Total' => 115])]);

        $this->assertSame(ImportStatus::Validated, $batch->status, json_encode($batch->errors()->pluck('message')));
        $this->assertNull($batch->errors()->where('code', 'unknown_column')->first());       // «Descuento» y «Sub Total» ya no se ignoran
        $this->assertSame('18.0000', $batch->summary['totals']['discount_total']);

        app(ImportRunner::class)->import($batch);
        $line = SalesLine::where('product_code', '6001')->sole();
        $this->assertSame(['180.0000', '18.0000', '162.0000'], [$line->subtotal, $line->discount_total, $line->net_total]);
    }

    public function test_net_total_must_equal_subtotal_minus_discount(): void
    {
        $batch = $this->validate([$this->line(['Descuento' => 20])]);   // 180 − 20 = 160, pero el neto es 162

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $e = $batch->errors()->where('code', 'subtotal_mismatch')->sole();
        $this->assertStringContainsString('(162.00) no coincide con «Sub Total» − «Descuento» (180.00 − 20.00 = 160.00)', $e->message);
    }

    public function test_negative_discount_is_rejected(): void
    {
        $batch = $this->validate([$this->line(['Descuento' => -5, 'Sub Total' => 157])]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertNotNull($batch->errors()->where('code', 'negative_amount')->where('column', 'Descuento')->first());
    }

    public function test_numeric_customer_and_product_codes_do_not_warn_about_lost_zeros(): void
    {
        $batch = $this->validate([$this->line()]);

        $this->assertNull($batch->errors()->where('code', 'numeric_code')->first());
    }
}
