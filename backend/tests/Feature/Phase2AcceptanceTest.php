<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Jobs\ConfirmImportJob;
use App\Jobs\ValidateImportJob;
use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Support\Xlsx;
use Tests\TestCase;

/**
 * Criterios de aceptación de la Fase 2 (docs/02_FASE_02_IMPORTACION_CALIDAD_DATOS.md y «Reglas de importación»
 * del contexto maestro), verificados de extremo a extremo por la API.
 */
class Phase2AcceptanceTest extends TestCase
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
        $alfa = Company::factory()->create(['code' => 'ALFA']);
        $beta = Company::factory()->create(['code' => 'BETA']);
        Branch::factory()->create(['company_id' => $alfa->id, 'code' => 'CEN']);
        Branch::factory()->create(['company_id' => $beta->id, 'code' => 'CEN']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->actingAs($this->admin, 'sanctum');
    }

    private function row(array $o = []): array
    {
        $d = [
            'Documento' => '0001', 'Serie' => 'A', 'Fecha' => '2026-10-01', 'Compañía' => 'ALFA', 'Sucursal' => 'CEN', 'Código Cliente' => '00077',
            'Nombre Cliente' => 'Cliente', 'Código del Producto' => '000123', 'Und.Vendidas' => 2, 'Nombre del Producto' => 'Filtro',
            'Costo Tot.Vta.' => 20, 'Costo Unit.Vta.' => 10, 'Precio Unit.Vta.' => 15, 'Precio Unit.Normal' => 16, 'Total Neto' => 30,
            'Total Impuesto' => 3.6, 'Venta + Imp' => 33.6, 'Gran Total' => 33.6, 'Nombre Vendedor' => 'María', 'Nombre Nivel 2 Inv' => 'Filtros',
            'Nombre Operador' => 'Caja 1', ...$o,
        ];

        return array_map(fn ($h) => $d[$h], self::HEADER);
    }

    private function send(array $rows, array $header = self::HEADER, ?string $path = null)
    {
        $path ??= Xlsx::make([$header, ...$rows]);

        return $this->post('/api/v1/admin/imports', ['type' => 'sales', 'file' => new UploadedFile($path, 'ventas.xlsx', null, null, true)], ['Accept' => 'application/json']);
    }

    private function confirm(int $id)
    {
        return $this->postJson("/api/v1/admin/imports/{$id}/confirm");
    }

    private function sales(): int
    {
        return SalesDocument::withoutGlobalScopes()->count();
    }

    public function test_incorrect_headers_are_rejected_and_every_missing_column_is_named(): void
    {
        $wrong = ['Doc', 'Serie', 'Cliente', 'Total'];

        $id = $this->send([['1', 'A', 'X', 10]], $wrong)->assertCreated()->assertJsonPath('data.status', 'rejected')->json('data.id');

        $missing = $this->getJson("/api/v1/admin/imports/{$id}/errors?code=missing_column")->json('data');
        $this->assertGreaterThanOrEqual(10, count($missing));
        $this->assertContains('Documento', array_column($missing, 'column'));
        $this->assertContains('Total Neto', array_column($missing, 'column'));
        $this->assertNotContains('Gran Total', array_column($missing, 'column'));   // opcional: se omite
        $this->confirm($id)->assertStatus(409);
        $this->assertSame(0, $this->sales());
    }

    public function test_invalid_rows_are_reported_by_row_and_column_and_block_the_whole_file(): void
    {
        $id = $this->send([
            $this->row(),
            $this->row(['Documento' => '0002', 'Total Neto' => 'abc']),
            $this->row(['Documento' => '0003', 'Fecha' => '31/02/2026']),
            $this->row(['Documento' => '0004', 'Nombre Cliente' => '']),
        ])->assertJsonPath('data.status', 'rejected')->json('data.id');

        $errors = collect($this->getJson("/api/v1/admin/imports/{$id}/errors")->json('data'));
        $this->assertEqualsCanonicalizing(
            [[3, 'Total Neto'], [4, 'Fecha'], [5, 'Nombre Cliente']],
            $errors->map(fn ($e) => [$e['row_number'], $e['column']])->all(),
        );
        // No hay importación parcial: la fila 2 era válida y tampoco se importó.
        $this->confirm($id)->assertStatus(409);
        $this->assertSame(0, $this->sales());
    }

    public function test_reimporting_the_same_file_is_never_a_silent_duplicate(): void
    {
        $path = Xlsx::make([self::HEADER, $this->row(), $this->row(['Documento' => '0002'])]);
        $first = $this->send([], path: $path)->json('data.id');
        $this->confirm($first)->assertStatus(202);
        $this->assertSame(2, $this->sales());

        // Mismo archivo otra vez: rechazado con un motivo explícito.
        $again = $this->send([], path: $path)->assertJsonPath('data.status', 'rejected')->json('data.id');
        $this->assertSame('already_imported', collect($this->getJson("/api/v1/admin/imports/{$again}/errors")->json('data'))->first()['code']);
        $this->confirm($again)->assertStatus(409);

        // Otro archivo que repite documentos ya importados: se informan, no se duplican.
        $overlap = $this->send([$this->row(), $this->row(['Documento' => '0003'])])
            ->assertJsonPath('data.status', 'validated')->assertJsonPath('data.documents_duplicate', 1)->assertJsonPath('data.documents_new', 1)->json('data.id');
        $this->confirm($overlap);

        $this->assertSame(3, $this->sales());
        $this->assertSame(1, SalesDocument::withoutGlobalScopes()->where('number', '0001')->count());
    }

    public function test_an_invoice_spread_over_several_rows_is_one_document_not_several_invoices(): void
    {
        $id = $this->send([$this->row(['Código del Producto' => 'P1']), $this->row(['Código del Producto' => 'P2']), $this->row(['Código del Producto' => 'P3'])])
            ->assertJsonPath('data.rows_total', 3)->assertJsonPath('data.documents_total', 1)->json('data.id');
        $this->confirm($id);

        $this->assertSame(1, $this->sales());
        $this->assertSame(3, SalesLine::withoutGlobalScopes()->count());
        $this->assertSame(3, SalesDocument::withoutGlobalScopes()->sole()->lines_count);
    }

    public function test_the_document_number_alone_is_not_the_identity(): void
    {
        $id = $this->send([
            $this->row(['Documento' => '0001', 'Serie' => 'A']),
            $this->row(['Documento' => '0001', 'Serie' => 'B']),                       // otra serie
            $this->row(['Documento' => '0001', 'Serie' => 'A', 'Compañía' => 'BETA']), // otra compañía
        ])->assertJsonPath('data.documents_total', 3)->json('data.id');
        $this->confirm($id);

        $this->assertSame(3, $this->sales());
    }

    public function test_codes_keep_their_leading_zeros(): void
    {
        $id = $this->send([$this->row(['Documento' => '000045', 'Código Cliente' => '00077', 'Código del Producto' => '000123'])])->json('data.id');
        $this->confirm($id);

        $doc = SalesDocument::withoutGlobalScopes()->sole();
        $this->assertSame(['000045', '00077'], [$doc->number, $doc->customer_code]);
        $this->assertSame('000123', SalesLine::withoutGlobalScopes()->sole()->product_code);
    }

    public function test_amount_differences_are_reported_and_never_silently_corrected(): void
    {
        $bad = $this->send([$this->row(['Venta + Imp' => 40.0])])->assertJsonPath('data.status', 'rejected')->json('data.id');
        $error = $this->getJson("/api/v1/admin/imports/{$bad}/errors")->json('data.0');
        $this->assertSame(['amount_mismatch', 'Venta + Imp'], [$error['code'], $error['column']]);
        $this->assertSame(0, $this->sales());

        // Dentro de la tolerancia de redondeo se acepta y se guarda tal como vino (no se «corrige»).
        $ok = $this->send([$this->row(['Documento' => '0002', 'Venta + Imp' => 33.605])])->assertJsonPath('data.status', 'validated')->json('data.id');
        $this->confirm($ok);
        $this->assertSame('33.6050', SalesLine::withoutGlobalScopes()->sole()->sale_plus_tax);
    }

    public function test_validation_mode_writes_no_sales_and_shows_a_preview_before_confirming(): void
    {
        $response = $this->send([$this->row(), $this->row(['Documento' => '0002'])])->assertJsonPath('data.status', 'validated');

        $response->assertJsonPath('data.summary.preview.0.values.Documento', '0001')->assertJsonPath('data.summary.totals.net_total', '60.0000');
        $this->assertSame(0, $this->sales()); // solo se validó
        $this->assertSame(0, SalesLine::withoutGlobalScopes()->count());

        $this->confirm($response->json('data.id'));
        $this->assertSame(2, $this->sales());
    }

    public function test_historical_sales_are_never_overwritten_or_deleted(): void
    {
        $id = $this->send([$this->row()])->json('data.id');
        $this->confirm($id);
        $before = SalesLine::withoutGlobalScopes()->sole()->net_total;

        // Un archivo que contradice una venta ya importada se rechaza como conflicto.
        $conflict = $this->send([$this->row(['Total Neto' => 50, 'Total Impuesto' => 6, 'Venta + Imp' => 56, 'Gran Total' => 56])])
            ->assertJsonPath('data.status', 'rejected')->json('data.id');
        $this->assertSame('document_conflict', collect($this->getJson("/api/v1/admin/imports/{$conflict}/errors")->json('data'))->first()['code']);

        // Un lote importado no se puede descartar, y los modelos no se modifican ni se borran.
        $this->postJson("/api/v1/admin/imports/{$id}/discard")->assertStatus(409);
        foreach ([fn () => SalesDocument::withoutGlobalScopes()->first()->delete(), fn () => SalesLine::withoutGlobalScopes()->first()->update(['units' => 1])] as $attempt) {
            try {
                $attempt();
                $this->fail('Debió impedirse');
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame($before, SalesLine::withoutGlobalScopes()->sole()->net_total);
    }

    public function test_every_import_is_traceable_file_user_date_result_and_errors(): void
    {
        $bad = $this->send([$this->row(['Total Neto' => 'abc'])])->json('data.id');
        $good = $this->send([$this->row(['Documento' => '9'])])->json('data.id');
        $this->confirm($good);

        $rejected = ImportBatch::find($bad);
        $this->assertSame(64, strlen($rejected->file_hash));
        $this->assertSame($this->admin->id, $rejected->user_id);
        $this->assertNotNull($rejected->validated_at);
        $this->assertSame([ImportStatus::Rejected, 1], [$rejected->status, $rejected->errors_total]);
        $this->assertSame(1, $rejected->errors()->count());

        $imported = ImportBatch::find($good);
        $this->assertSame(ImportStatus::Imported, $imported->status);
        $this->assertNotNull($imported->finished_at);
        $this->assertSame($good, SalesDocument::withoutGlobalScopes()->sole()->import_batch_id); // cada venta apunta a su lote
        $this->assertDatabaseHas('audit_logs', ['event' => 'import.completed', 'auditable_id' => $good]);
    }

    public function test_validation_and_import_run_in_the_queue_not_in_the_request(): void
    {
        Queue::fake();

        $id = $this->send([$this->row()])->assertJsonPath('data.status', 'pending')->json('data.id');
        Queue::assertPushed(ValidateImportJob::class, fn ($job) => $job->batchId === $id);
        $this->assertSame(0, $this->sales());

        ImportBatch::whereKey($id)->update(['status' => ImportStatus::Validated]);
        $this->confirm($id)->assertStatus(202)->assertJsonPath('data.status', 'importing');
        Queue::assertPushed(ConfirmImportJob::class, fn ($job) => $job->batchId === $id);
        $this->assertSame(0, $this->sales()); // sigue sin escribirse hasta que corra el worker
    }

    public function test_stale_batches_are_marked_failed_so_the_screen_does_not_wait_forever(): void
    {
        $stale = ImportBatch::factory()->status(ImportStatus::Importing)->create();
        $recent = ImportBatch::factory()->status(ImportStatus::Pending)->create();
        $done = ImportBatch::factory()->status(ImportStatus::Imported)->create();
        ImportBatch::whereIn('id', [$stale->id, $done->id])->update(['updated_at' => now()->subHours(3)]);

        $this->artisan('imports:fail-stale')->expectsOutput('Lotes marcados como fallidos: 1')->assertSuccessful();

        $this->assertSame(ImportStatus::Failed, $stale->fresh()->status);
        $this->assertStringContainsString('No se escribió ningún dato', $stale->fresh()->failure_message);
        $this->assertSame(ImportStatus::Pending, $recent->fresh()->status);
        $this->assertSame(ImportStatus::Imported, $done->fresh()->status);
    }
}
