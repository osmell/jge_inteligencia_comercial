<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Xlsx;
use Tests\TestCase;

class CustomersImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Compañía', 'Sucursal', 'Código', 'Descripción o Nombre', 'Teléfono', 'Email', 'Whatsapp', 'Dirección', 'Nombre Comercial', 'Nombre Contacto'];

    private Company $alfa;

    private Company $beta;

    private Branch $central;

    private Branch $norte;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['code' => 'ALFA', 'name' => 'Compañía Alfa']);
        $this->beta = Company::factory()->create(['code' => 'BETA', 'name' => 'Compañía Beta']);
        $this->central = Branch::factory()->create(['company_id' => $this->alfa->id, 'code' => 'CEN', 'name' => 'Central']);
        $this->norte = Branch::factory()->create(['company_id' => $this->alfa->id, 'code' => 'NOR', 'name' => 'Norte']);
        Branch::factory()->create(['company_id' => $this->beta->id, 'code' => 'CEN', 'name' => 'Central Beta']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    private function row(array $o = []): array
    {
        $d = [
            'Compañía' => 'ALFA', 'Sucursal' => 'CEN', 'Código' => 1001, 'Descripción o Nombre' => 'Taller Pérez', 'Teléfono' => '5555-1234',
            'Email' => 'taller@example.com', 'Whatsapp' => '+502 5555 9999', 'Dirección' => 'Zona 1', 'Nombre Comercial' => 'Pérez Motors',
            'Nombre Contacto' => 'Pedro', ...$o,
        ];

        return array_map(fn ($h) => $d[$h], self::HEADER);
    }

    private function validate(array $rows, ?User $user = null): ImportBatch
    {
        $path = Xlsx::make([self::HEADER, ...$rows]);
        $stored = 'customers/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));
        $batch = ImportBatch::factory()->create([
            'type' => 'customers', 'status' => ImportStatus::Pending, 'stored_path' => $stored,
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

    public function test_inserts_new_customers_with_all_their_data(): void
    {
        $batch = $this->import([$this->row(), $this->row(['Código' => 1002, 'Descripción o Nombre' => 'Ferretería Gómez', 'Teléfono' => null, 'Email' => null])]);

        $this->assertSame(ImportStatus::Imported, $batch->status);
        $this->assertSame(['new' => 2, 'updated' => 0, 'unchanged' => 0], $batch->summary['customers']);

        $c = Customer::where('code', '1001')->sole();
        $this->assertSame($this->alfa->id, $c->company_id);
        $this->assertSame($this->central->id, $c->branch_id);
        $this->assertSame(['Taller Pérez', 'Pérez Motors', 'Pedro', '5555-1234', '+502 5555 9999', 'taller@example.com', 'Zona 1', 'import'],
            [$c->name, $c->trade_name, $c->contact_name, $c->phone, $c->whatsapp, $c->email, $c->address, $c->source]);
        $this->assertNull(Customer::where('code', '1002')->value('phone'));
    }

    public function test_updates_existing_customers_and_reports_changes_and_unchanged(): void
    {
        $this->import([$this->row(), $this->row(['Código' => 1002, 'Descripción o Nombre' => 'Ferretería Gómez'])]);

        $batch = $this->import([
            $this->row(['Descripción o Nombre' => 'Taller Pérez S.A.', 'Sucursal' => 'NOR', 'Teléfono' => null]),    // cambia nombre, sucursal y borra el teléfono
            $this->row(['Código' => 1002, 'Descripción o Nombre' => 'Ferretería Gómez']),                            // igual
            $this->row(['Código' => 1003, 'Descripción o Nombre' => 'Nuevo']),
        ]);

        $this->assertSame(['new' => 1, 'updated' => 1, 'unchanged' => 1], $batch->summary['customers']);
        $c = Customer::where('code', '1001')->sole();
        $this->assertSame('Taller Pérez S.A.', $c->name);
        $this->assertSame($this->norte->id, $c->branch_id);
        $this->assertNull($c->phone);
        $this->assertSame(3, Customer::count());
    }

    public function test_same_code_in_two_companies_is_two_customers(): void
    {
        $this->import([$this->row(), $this->row(['Compañía' => 'BETA', 'Descripción o Nombre' => 'Otro cliente'])]);

        $this->assertSame(2, Customer::where('code', '1001')->count());
        $this->assertSame('Otro cliente', Customer::where('code', '1001')->where('company_id', $this->beta->id)->value('name'));
    }

    public function test_rejects_invalid_rows_with_row_and_column(): void
    {
        $batch = $this->validate([
            $this->row(['Código' => 'AB12']),
            $this->row(['Código' => 12.5]),
            $this->row(['Código' => 1003, 'Email' => 'sin-arroba']),
            $this->row(['Código' => 1004, 'Teléfono' => 'llamar luego']),
            $this->row(['Código' => 1005, 'Compañía' => 'NOEXISTE']),
            $this->row(['Código' => 1006, 'Sucursal' => 'NOEXISTE']),
            $this->row(['Código' => 1007, 'Descripción o Nombre' => null]),
        ]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $codes = $batch->errors()->pluck('code')->all();
        foreach (['invalid_customer_code', 'invalid_email', 'invalid_phone', 'unknown_company', 'unknown_branch', 'required'] as $expected) {
            $this->assertContains($expected, $codes);
        }
        $this->assertSame(0, Customer::count());
    }

    public function test_codes_keep_leading_zeros_and_accept_excel_numbers(): void
    {
        $this->import([$this->row(['Código' => '000123']), $this->row(['Código' => 456.0, 'Descripción o Nombre' => 'Numérico'])]);

        $this->assertEqualsCanonicalizing(['000123', '456'], Customer::pluck('code')->all());
    }

    public function test_conflicting_duplicate_rows_in_the_same_file_are_rejected(): void
    {
        $batch = $this->validate([$this->row(), $this->row(['Descripción o Nombre' => 'Otro nombre'])]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertContains('inconsistent_group', $batch->errors()->pluck('code')->all());
    }

    public function test_identical_duplicate_row_is_only_a_warning(): void
    {
        $batch = $this->validate([$this->row(), $this->row()]);

        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertContains('duplicate_row', $batch->errors()->pluck('code')->all());
    }

    public function test_user_without_full_access_to_the_company_cannot_import_it(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->beta);

        $batch = $this->validate([$this->row()], $user);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertContains('company_out_of_scope', $batch->errors()->pluck('code')->all());
    }

    public function test_template_lists_the_agreed_columns(): void
    {
        $this->actingAs($this->admin, 'sanctum')->get('/api/v1/admin/imports/templates/customers')->assertOk();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/imports/types')
            ->assertJsonFragment(['key' => 'customers', 'label' => 'Clientes']);
    }
}
