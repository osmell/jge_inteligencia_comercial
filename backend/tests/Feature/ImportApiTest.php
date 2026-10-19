<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Reader\XLSX\Reader;
use Tests\Support\Xlsx;
use Tests\TestCase;

class ImportApiTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = [
        'Documento', 'Serie', 'Fecha', 'Compañía', 'Sucursal', 'Código Cliente', 'Nombre Cliente', 'Código del Producto', 'Und.Vendidas',
        'Nombre del Producto', 'Costo Tot.Vta.', 'Costo Unit.Vta.', 'Precio Unit.Vta.', 'Precio Unit.Normal', 'Total Neto', 'Total Impuesto',
        'Venta + Imp', 'Gran Total', 'Nombre Vendedor', 'Nombre Nivel 2 Inv', 'Nombre Operador',
    ];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create(['code' => 'ALFA']);
        Branch::factory()->create(['company_id' => $company->id, 'code' => 'CEN']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    private function row(array $o = []): array
    {
        $d = [
            'Documento' => '0001', 'Serie' => 'A', 'Fecha' => '2026-10-01', 'Compañía' => 'ALFA', 'Sucursal' => 'CEN', 'Código Cliente' => 'C1',
            'Nombre Cliente' => 'Cliente', 'Código del Producto' => '000123', 'Und.Vendidas' => 2, 'Nombre del Producto' => 'Filtro',
            'Costo Tot.Vta.' => 20, 'Costo Unit.Vta.' => 10, 'Precio Unit.Vta.' => 15, 'Precio Unit.Normal' => 16, 'Total Neto' => 30,
            'Total Impuesto' => 3.6, 'Venta + Imp' => 33.6, 'Gran Total' => 33.6, 'Nombre Vendedor' => 'María', 'Nombre Nivel 2 Inv' => 'Filtros',
            'Nombre Operador' => 'Caja 1', ...$o,
        ];

        return array_map(fn ($h) => $d[$h], self::HEADER);
    }

    private function file(array $rows, string $name = 'ventas.xlsx'): UploadedFile
    {
        return new UploadedFile(Xlsx::make([self::HEADER, ...$rows]), $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function upload(array $rows, ?User $as = null, string $name = 'ventas.xlsx')
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')
            ->post('/api/v1/admin/imports', ['type' => 'sales', 'file' => $this->file($rows, $name)], ['Accept' => 'application/json']);
    }

    private function importer(): User
    {
        $this->grant();
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach(Company::first()); // acceso completo a ALFA

        return $user;
    }

    private function grant(): void
    {
        Role::where('slug', Role::GERENTE_GENERAL)->first()->permissions()->sync(Permission::where('slug', 'imports.manage')->pluck('id'));
    }

    public function test_every_endpoint_requires_the_imports_permission(): void
    {
        $batch = ImportBatch::factory()->create();
        $nobody = User::factory()->role(Role::VENDEDOR)->create();

        $this->getJson('/api/v1/admin/imports')->assertUnauthorized();
        $this->actingAs($nobody, 'sanctum');
        foreach (['get' => ['', '/types', '/templates/sales', "/{$batch->id}", "/{$batch->id}/errors", "/{$batch->id}/errors.csv"], 'post' => ['', "/{$batch->id}/confirm", "/{$batch->id}/discard"]] as $verb => $paths) {
            foreach ($paths as $path) {
                $this->{$verb.'Json'}('/api/v1/admin/imports'.$path)->assertForbidden();
            }
        }
    }

    public function test_upload_stores_the_file_privately_and_validates_it(): void
    {
        $response = $this->upload([$this->row(), $this->row(['Documento' => '0002'])], name: '../../etc/ventas octubre.xlsx')->assertCreated();

        $batch = ImportBatch::sole();
        $this->assertSame('ventas octubre.xlsx', $batch->original_name); // sin ruta
        $this->assertStringNotContainsString('ventas', $batch->stored_path); // se guarda con nombre aleatorio
        $this->assertMatchesRegularExpression('#^sales/\d{4}/\d{2}/[0-9a-f-]{36}\.xlsx$#', $batch->stored_path);
        Storage::disk('imports')->assertExists($batch->stored_path);
        $this->assertSame(64, strlen($batch->file_hash));

        // Con la cola síncrona de pruebas el trabajo ya corrió.
        $response->assertJsonPath('data.status', 'validated')->assertJsonPath('data.documents_total', 2)
            ->assertJsonPath('data.status_label', 'Validado')->assertJsonPath('data.can_confirm', true)
            ->assertJsonPath('data.user.username', $this->admin->username);
        $this->assertSame(0, SalesDocument::withoutGlobalScopes()->count()); // subir no escribe ventas
        $this->assertDatabaseHas('audit_logs', ['event' => 'import.uploaded', 'user_id' => $this->admin->id]);
    }

    public function test_upload_validates_type_and_file(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $accept = ['Accept' => 'application/json'];

        $this->post('/api/v1/admin/imports', ['type' => 'sales'], $accept)->assertJsonValidationErrors('file');
        $this->post('/api/v1/admin/imports', ['type' => 'otro', 'file' => $this->file([$this->row()])], $accept)->assertJsonValidationErrors('type');
        $txt = UploadedFile::fake()->createWithContent('ventas.txt', 'hola');
        $this->post('/api/v1/admin/imports', ['type' => 'sales', 'file' => $txt], $accept)->assertJsonValidationErrors('file');
        config(['imports.max_file_kb' => 1]);
        $this->post('/api/v1/admin/imports', ['type' => 'sales', 'file' => $this->file([$this->row()])], $accept)->assertJsonValidationErrors('file');
        $this->assertSame(0, ImportBatch::count());
    }

    public function test_an_invalid_file_is_rejected_with_errors_available(): void
    {
        $this->upload([$this->row(['Total Neto' => 'abc']), $this->row(['Documento' => '2', 'Venta + Imp' => 99])])
            ->assertJsonPath('data.status', 'rejected')->assertJsonPath('data.can_confirm', false)->assertJsonPath('data.can_discard', true)
            ->assertJsonPath('data.errors_total', 2);
        $batch = ImportBatch::sole();

        $this->getJson("/api/v1/admin/imports/{$batch->id}/errors")
            ->assertOk()->assertJsonCount(2, 'data')->assertJsonPath('total', 2)
            ->assertJsonPath('data.0.row_number', 2)->assertJsonPath('data.0.column', 'Total Neto');
        $this->getJson("/api/v1/admin/imports/{$batch->id}/errors?code=amount_mismatch")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/admin/imports/{$batch->id}/errors?severity=warning")->assertJsonCount(0, 'data');
    }

    public function test_confirm_imports_once_and_a_second_confirmation_is_refused(): void
    {
        $id = $this->upload([$this->row()])->json('data.id');

        $this->postJson("/api/v1/admin/imports/{$id}/confirm")->assertStatus(202)->assertJsonPath('data.status', 'imported');
        $this->assertSame(1, SalesDocument::withoutGlobalScopes()->count());

        $this->postJson("/api/v1/admin/imports/{$id}/confirm")->assertStatus(409);
        $this->assertSame(1, SalesDocument::withoutGlobalScopes()->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'import.confirmed']);
    }

    public function test_a_rejected_batch_cannot_be_confirmed(): void
    {
        $id = $this->upload([$this->row(['Total Neto' => 'abc'])])->json('data.id');

        $this->postJson("/api/v1/admin/imports/{$id}/confirm")->assertStatus(409);
        $this->assertSame(0, SalesDocument::withoutGlobalScopes()->count());
    }

    public function test_confirm_is_claimed_atomically(): void
    {
        $id = $this->upload([$this->row()])->json('data.id');
        // Simula que otra petición ya tomó el lote entre la lectura y la confirmación.
        ImportBatch::whereKey($id)->update(['status' => ImportStatus::Importing]);

        $this->postJson("/api/v1/admin/imports/{$id}/confirm")->assertStatus(409);
    }

    public function test_discard_removes_the_file_and_blocks_further_use(): void
    {
        $id = $this->upload([$this->row()])->json('data.id');
        $path = ImportBatch::find($id)->stored_path;

        $this->postJson("/api/v1/admin/imports/{$id}/discard")->assertOk()->assertJsonPath('data.status', 'discarded');

        Storage::disk('imports')->assertMissing($path);
        $this->postJson("/api/v1/admin/imports/{$id}/confirm")->assertStatus(409);
        $this->postJson("/api/v1/admin/imports/{$id}/discard")->assertStatus(409);
        $this->assertDatabaseHas('audit_logs', ['event' => 'import.discarded']);
        $this->assertSame(0, SalesDocument::withoutGlobalScopes()->count());
    }

    public function test_an_imported_batch_cannot_be_discarded(): void
    {
        $id = $this->upload([$this->row()])->json('data.id');
        $this->postJson("/api/v1/admin/imports/{$id}/confirm");

        $this->postJson("/api/v1/admin/imports/{$id}/discard")->assertStatus(409);
        $this->assertSame(1, SalesDocument::withoutGlobalScopes()->count());
    }

    public function test_the_same_file_cannot_be_imported_twice_through_the_api(): void
    {
        $path = Xlsx::make([self::HEADER, $this->row()]);
        $send = fn () => $this->actingAs($this->admin, 'sanctum')->post('/api/v1/admin/imports', [
            'type' => 'sales', 'file' => new UploadedFile($path, 'ventas.xlsx', null, null, true),
        ], ['Accept' => 'application/json']);

        $first = $send()->json('data.id');
        $this->postJson("/api/v1/admin/imports/{$first}/confirm")->assertStatus(202);

        $second = $send()->assertJsonPath('data.status', 'rejected');
        $this->assertSame('already_imported', ImportBatch::find($second->json('data.id'))->errors()->value('code'));
        $this->assertSame(1, SalesDocument::withoutGlobalScopes()->count());
    }

    public function test_errors_csv_has_bom_headers_and_neutralizes_formulas(): void
    {
        $id = $this->upload([$this->row(['Total Neto' => '=HYPERLINK("http://x")'])])->json('data.id');

        $response = $this->get("/api/v1/admin/imports/{$id}/errors.csv", ['Accept' => 'text/csv'])->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Fila,Columna,Severidad,Código,Mensaje,Valor', $csv);
        $this->assertStringContainsString('"\'=HYPERLINK', $csv);          // el valor no empieza por "="
        $this->assertStringNotContainsString(',"=HYPERLINK', $csv);
        $this->assertStringContainsString('attachment; filename=errores-importacion-'.$id.'.csv', $response->headers->get('content-disposition'));
    }

    public function test_listing_filters_and_scopes_batches_by_owner(): void
    {
        $other = $this->importer();
        $mine = $this->importer();
        $this->upload([$this->row()], $other);
        $this->upload([$this->row(['Documento' => '2'])], $mine);
        $this->upload([$this->row(['Documento' => '3', 'Total Neto' => 'abc'])], $this->admin);

        // El Administrador ve todos; el resto, solo los suyos.
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/imports')->assertJsonPath('total', 3);
        $this->getJson('/api/v1/admin/imports?status=rejected')->assertJsonPath('total', 1);
        $this->actingAs($mine, 'sanctum')->getJson('/api/v1/admin/imports')->assertJsonPath('total', 1)->assertJsonPath('data.0.user.id', $mine->id);

        $foreign = ImportBatch::where('user_id', $other->id)->first();
        $this->getJson("/api/v1/admin/imports/{$foreign->id}")->assertNotFound();
        $this->getJson("/api/v1/admin/imports/{$foreign->id}/errors")->assertNotFound();
        $this->postJson("/api/v1/admin/imports/{$foreign->id}/confirm")->assertNotFound();
        $this->postJson("/api/v1/admin/imports/{$foreign->id}/discard")->assertNotFound();
    }

    public function test_detail_includes_the_summary_and_preview_but_the_list_does_not(): void
    {
        $id = $this->upload([$this->row()])->json('data.id');

        $this->getJson("/api/v1/admin/imports/{$id}")
            ->assertJsonPath('data.summary.totals.net_total', '30.0000')
            ->assertJsonPath('data.summary.preview.0.values.Documento', '0001');
        $this->assertArrayNotHasKey('summary', $this->getJson('/api/v1/admin/imports')->json('data.0'));
    }

    public function test_types_and_template_download(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/imports/types')->assertJson(['data' => [['key' => 'sales', 'label' => 'Ventas']]]);
        $this->getJson('/api/v1/admin/imports/templates/inexistente')->assertNotFound();

        $response = $this->get('/api/v1/admin/imports/templates/sales')->assertOk();
        $this->assertStringContainsString('plantilla-ventas.xlsx', $response->headers->get('content-disposition'));

        $file = $response->baseResponse->getFile()->getPathname();
        $reader = new Reader;
        $reader->open($file);
        $sheets = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            $rows = [];
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
            $sheets[$sheet->getName()] = $rows;
        }
        $reader->close();

        $this->assertSame(self::HEADER, $sheets['Ventas'][0]);
        $this->assertCount(1, $sheets['Ventas']); // en blanco: solo encabezados
        $this->assertSame('Instrucciones', array_keys($sheets)[1]);
        $this->assertSame(['Columna', 'Obligatoria', 'Descripción'], $sheets['Instrucciones'][0]);
        $this->assertContains('Costo Unit.Vta.', array_column($sheets['Instrucciones'], 0));

        // La plantilla en blanco subida tal cual se rechaza por no tener filas, no por encabezados.
        $upload = new UploadedFile($file, 'plantilla.xlsx', null, null, true);
        $result = $this->post('/api/v1/admin/imports', ['type' => 'sales', 'file' => $upload], ['Accept' => 'application/json']);
        $this->assertSame('no_data_rows', ImportBatch::find($result->json('data.id'))->errors()->value('code'));
    }

    public function test_audit_trail_covers_the_whole_flow(): void
    {
        $id = $this->upload([$this->row()])->json('data.id');
        $this->postJson("/api/v1/admin/imports/{$id}/confirm");

        $this->assertEqualsCanonicalizing(
            ['import.uploaded', 'import.confirmed', 'import.completed'],
            AuditLog::where('event', 'like', 'import.%')->pluck('event')->all(),
        );
    }
}
