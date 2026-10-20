<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Xlsx;
use Tests\TestCase;

class ProductsImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Compañía', 'Sucursal', 'Código', 'Descripción o Nombre', 'Nivel 1', 'Nivel 2', 'Nivel 3'];

    private Company $alfa;

    private Company $beta;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['code' => 'ALFA']);
        $this->beta = Company::factory()->create(['code' => 'BETA']);
        Branch::factory()->create(['company_id' => $this->alfa->id, 'code' => 'CEN', 'name' => 'Central']);
        Branch::factory()->create(['company_id' => $this->alfa->id, 'code' => 'NOR', 'name' => 'Norte']);
        Branch::factory()->create(['company_id' => $this->beta->id, 'code' => 'CEN', 'name' => 'Central Beta']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    private function row(array $o = []): array
    {
        $d = ['Compañía' => 'ALFA', 'Sucursal' => 'CEN', 'Código' => 500, 'Descripción o Nombre' => 'Filtro de aceite', 'Nivel 1' => 'Repuestos', 'Nivel 2' => 'Motor', 'Nivel 3' => 'Filtros', ...$o];

        return array_map(fn ($h) => $d[$h], self::HEADER);
    }

    private function validate(array $rows, ?User $user = null): ImportBatch
    {
        $path = Xlsx::make([self::HEADER, ...$rows]);
        $stored = 'products/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));
        $batch = ImportBatch::factory()->create([
            'type' => 'products', 'status' => ImportStatus::Pending, 'stored_path' => $stored,
            'file_hash' => hash_file('sha256', $path), 'user_id' => ($user ?? $this->admin)->id,
        ]);
        app(ImportRunner::class)->validate($batch);

        return $batch->fresh();
    }

    private function import(array $rows): ImportBatch
    {
        $batch = $this->validate($rows);
        $this->assertSame(ImportStatus::Validated, $batch->status, json_encode($batch->errors()->pluck('message')));
        app(ImportRunner::class)->import($batch);

        return $batch->fresh();
    }

    public function test_inserts_products_with_their_three_levels_and_updates_existing_ones(): void
    {
        $batch = $this->import([$this->row(), $this->row(['Código' => 501, 'Descripción o Nombre' => 'Aceite', 'Nivel 1' => 'Lubricantes', 'Nivel 2' => null, 'Nivel 3' => null])]);
        $this->assertSame(['new' => 2, 'updated' => 0, 'unchanged' => 0], $batch->summary['products']);
        $p = Product::where('code', '500')->sole();
        $this->assertSame(['Filtro de aceite', 'Repuestos', 'Motor', 'Filtros'], [$p->name, $p->level1, $p->level2, $p->level3]);

        $second = $this->import([$this->row(['Nivel 3' => 'Filtros de motor']), $this->row(['Código' => 501, 'Descripción o Nombre' => 'Aceite', 'Nivel 1' => 'Lubricantes', 'Nivel 2' => null, 'Nivel 3' => null])]);
        $this->assertSame(['new' => 0, 'updated' => 1, 'unchanged' => 1], $second->summary['products']);
        $this->assertSame('Filtros de motor', $p->fresh()->level3);
        $this->assertSame(2, Product::count());
    }

    public function test_same_product_in_several_branches_is_not_a_conflict(): void
    {
        $batch = $this->import([$this->row(), $this->row(['Sucursal' => 'NOR'])]);
        $this->assertSame(1, $batch->summary['products']['new']);
        $this->assertSame(1, Product::count());
    }

    public function test_same_code_in_two_companies_is_two_products_and_conflicting_rows_are_rejected(): void
    {
        $this->import([$this->row(), $this->row(['Compañía' => 'BETA'])]);
        $this->assertSame(2, Product::count());

        $batch = $this->validate([$this->row(['Código' => 900]), $this->row(['Código' => 900, 'Nivel 2' => 'Otra línea'])]);
        $this->assertSame(ImportStatus::Rejected, $batch->status);
    }

    public function test_rejects_invalid_rows(): void
    {
        $batch = $this->validate([
            $this->row(['Código' => 'AB1']),
            $this->row(['Código' => 502, 'Descripción o Nombre' => null]),
            $this->row(['Código' => 503, 'Compañía' => 'NOEXISTE']),
            $this->row(['Código' => 504, 'Sucursal' => 'NOEXISTE']),
        ]);
        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $codes = $batch->errors()->pluck('code')->all();
        foreach (['invalid_product_code', 'required', 'unknown_company', 'unknown_branch'] as $expected) {
            $this->assertContains($expected, $codes);
        }
        $this->assertSame(0, Product::count());
    }

    public function test_template_and_type_are_listed(): void
    {
        $this->actingAs($this->admin, 'sanctum')->get('/api/v1/admin/imports/templates/products')->assertOk();
        $this->getJson('/api/v1/admin/imports/types')->assertJsonFragment(['key' => 'products', 'label' => 'Productos']);
    }

    public function test_invisible_control_characters_are_removed_from_names(): void
    {
        $this->import([$this->row(['Descripción o Nombre' => "EQUI \x1FKLASS 505"])]);
        $this->assertSame('EQUI KLASS 505', Product::where('code', '500')->value('name'));
    }

    public function test_conflicts_and_repeats_are_explained_in_terms_of_the_product_code(): void
    {
        $batch = $this->validate([
            $this->row(['Código' => 700]),
            $this->row(['Código' => 700, 'Sucursal' => 'NOR']),                         // idéntica en otra sucursal: solo aviso
            $this->row(['Código' => 700, 'Nivel 3' => 'Otra categoría']),               // niveles distintos: error
        ]);

        $messages = $batch->errors()->pluck('message')->all();
        $this->assertContains('El código 700 aparece en varias filas con «Nivel 3» distinto (la primera está en la fila 2).', $messages);
        $this->assertContains('Fila repetida con los mismos datos para el código 700.', $messages);
        $this->assertSame(ImportStatus::Rejected, $batch->status);
    }
}
