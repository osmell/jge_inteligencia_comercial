<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerType;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Xlsx;
use Tests\TestCase;

class CustomersImportLinksTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = ['Compañía', 'Sucursal', 'Código', 'Descripción o Nombre'];

    private Company $alfa;

    private User $admin;

    private CustomerType $taller;

    private Seller $ana;

    private Operator $caja;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['code' => 'ALFA']);
        Branch::factory()->create(['company_id' => $this->alfa->id, 'code' => 'CEN', 'name' => 'Central']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->taller = CustomerType::create(['name' => 'Taller']);
        CustomerType::create(['name' => 'Antiguo', 'active' => false]);
        $this->ana = Seller::create(['company_id' => $this->alfa->id, 'name' => 'Ana Torres', 'normalized_name' => 'ana torres']);
        $this->caja = Operator::create(['company_id' => $this->alfa->id, 'name' => 'Caja 1', 'normalized_name' => 'caja 1']);
    }

    /** @param  list<string>  $extra  encabezados opcionales */
    private function validate(array $extra, array $rows): ImportBatch
    {
        $header = [...self::BASE, ...$extra];
        $path = Xlsx::make([$header, ...array_map(fn ($r) => array_slice(['ALFA', 'CEN', ...$r], 0, count($header)), $rows)]);
        $stored = 'customers/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));
        $batch = ImportBatch::factory()->create(['type' => 'customers', 'status' => ImportStatus::Pending, 'stored_path' => $stored, 'file_hash' => hash_file('sha256', $path), 'user_id' => $this->admin->id]);
        app(ImportRunner::class)->validate($batch);

        return $batch->fresh();
    }

    private function import(array $extra, array $rows): ImportBatch
    {
        $batch = $this->validate($extra, $rows);
        $this->assertSame(ImportStatus::Validated, $batch->status, json_encode($batch->errors()->pluck('message')));
        app(ImportRunner::class)->import($batch);

        return $batch->fresh();
    }

    public function test_assigns_type_seller_and_operator_by_name_ignoring_case_and_spacing(): void
    {
        $this->import(['Tipo', 'Vendedor', 'Facturador'], [[1001, 'Taller Pérez', ' taller ', 'ANA  torres', 'Caja 1']]);

        $c = Customer::where('code', '1001')->sole();
        $this->assertSame([$this->taller->id, $this->ana->id, $this->caja->id], [$c->customer_type_id, $c->seller_id, $c->operator_id]);
    }

    public function test_a_file_without_these_columns_leaves_assignments_untouched_and_a_blank_cell_clears_them(): void
    {
        $this->import(['Tipo', 'Vendedor', 'Facturador'], [[1001, 'Taller Pérez', 'Taller', 'Ana Torres', 'Caja 1']]);

        $this->import([], [[1001, 'Taller Pérez S.A.']]);          // sin las columnas: no toca nada
        $c = Customer::where('code', '1001')->sole();
        $this->assertSame(['Taller Pérez S.A.', $this->taller->id, $this->ana->id, $this->caja->id], [$c->name, $c->customer_type_id, $c->seller_id, $c->operator_id]);

        $batch = $this->import(['Tipo', 'Vendedor'], [[1001, 'Taller Pérez S.A.', null, 'Ana Torres']]);   // Tipo vacío lo quita; Facturador no viene
        $c->refresh();
        $this->assertSame([null, $this->ana->id, $this->caja->id], [$c->customer_type_id, $c->seller_id, $c->operator_id]);
        $this->assertSame(1, $batch->summary['customers']['updated']);
    }

    public function test_only_links_changing_counts_as_an_update(): void
    {
        $this->import(['Tipo'], [[1001, 'Taller Pérez', 'Taller']]);
        $batch = $this->import(['Tipo'], [[1001, 'Taller Pérez', 'Taller'], [1002, 'Otro', null]]);   // otro archivo (el mismo se rechaza por duplicado)
        $this->assertSame(['new' => 1, 'updated' => 0, 'unchanged' => 1], $batch->summary['customers']);
    }

    public function test_unknown_or_inactive_names_are_rejected_with_their_column(): void
    {
        $batch = $this->validate(['Tipo', 'Vendedor', 'Facturador'], [
            [1001, 'A', 'Inventado', null, null],
            [1002, 'B', 'Antiguo', null, null],
            [1003, 'C', null, 'Nadie', null],
            [1004, 'D', null, null, 'Nadie'],
        ]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertEqualsCanonicalizing(['unknown_type', 'unknown_type', 'unknown_seller', 'unknown_operator'], $batch->errors()->pluck('code')->all());
        $this->assertSame(0, Customer::count());

        $this->ana->update(['is_active' => false]);
        $this->assertSame(ImportStatus::Rejected, $this->validate(['Vendedor'], [[1005, 'E', 'Ana Torres']])->status);
    }

    public function test_a_seller_from_another_company_is_not_accepted(): void
    {
        $beta = Company::factory()->create(['code' => 'BETA']);
        Seller::create(['company_id' => $beta->id, 'name' => 'Solo Beta', 'normalized_name' => 'solo beta']);

        $this->assertSame(ImportStatus::Rejected, $this->validate(['Vendedor'], [[1001, 'A', 'Solo Beta']])->status);
    }
}
