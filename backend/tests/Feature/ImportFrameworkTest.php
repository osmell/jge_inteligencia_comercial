<?php

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\ErrorCollector;
use App\Imports\ImportRow;
use App\Imports\ImportRunner;
use App\Jobs\ValidateImportJob;
use App\Models\ImportBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeImportType;
use Tests\Support\Xlsx;
use Tests\TestCase;

class ImportFrameworkTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Documento', 'Cliente', 'Código Producto', 'Importe', 'Fecha'];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
        config(['imports.types' => ['fake' => FakeImportType::class]]);
        FakeImportType::$explode = false;
    }

    private function batch(array $rows, array $attrs = []): ImportBatch
    {
        $path = Xlsx::make($rows);

        return $this->batchFromFile($path, $attrs);
    }

    private function batchFromFile(string $path, array $attrs = []): ImportBatch
    {
        $stored = 'fake/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));

        return ImportBatch::factory()->create([
            'type' => 'fake', 'status' => ImportStatus::Pending, 'stored_path' => $stored,
            'file_hash' => hash_file('sha256', $path), ...$attrs,
        ]);
    }

    private function validate(ImportBatch $batch): ImportBatch
    {
        app(ImportRunner::class)->validate($batch);

        return $batch->fresh();
    }

    private function errorsOf(ImportBatch $batch, string $code): array
    {
        return $batch->errors()->where('code', $code)->get()->all();
    }

    public function test_valid_file_is_validated_and_groups_lines_by_document(): void
    {
        $batch = $this->validate($this->batch([
            self::HEADER,
            ['0001', 'Cliente A', 'P1', 10.5, '2026-10-01'],
            ['0001', 'Cliente A', 'P2', 20, '2026-10-01'],
            ['0002', 'Cliente B', 'P1', '5.25', '2026-10-02'],
        ]));

        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertSame(3, $batch->rows_total);
        $this->assertSame(2, $batch->documents_total);
        $this->assertSame(2, $batch->documents_new);
        $this->assertSame(0, $batch->errors_total);
        $this->assertSame('ok', $batch->summary['custom']);
        $this->assertNotNull($batch->validated_at);
    }

    public function test_preview_shows_first_rows_with_canonical_headers(): void
    {
        $batch = $this->validate($this->batch([self::HEADER, ['0001', 'Cliente A', 'P1', 10, '2026-10-01']]));

        $this->assertSame(2, $batch->summary['preview'][0]['row']);
        $this->assertSame('Cliente A', $batch->summary['preview'][0]['values']['Cliente']);
        $this->assertSame('0001', $batch->summary['preview'][0]['values']['Documento']);
        $this->assertSame(['Documento', 'Cliente', 'Código Producto', 'Importe', 'Fecha', 'Nota'], $batch->summary['preview_columns']);
    }

    public function test_preview_is_limited(): void
    {
        config(['imports.preview_rows' => 2]);
        $rows = [self::HEADER];
        for ($i = 1; $i <= 5; $i++) {
            $rows[] = ["000{$i}", 'C', 'P', 1, '2026-10-01'];
        }

        $this->assertCount(2, $this->validate($this->batch($rows))->summary['preview']);
    }

    public function test_headers_accept_aliases_case_accents_and_extra_spaces(): void
    {
        $batch = $this->validate($this->batch([
            ['documento', ' NOMBRE   CLIENTE ', 'codigo producto', 'IMPORTE', 'fecha', 'Nota'],
            ['0001', 'A', 'P', 1, '2026-10-01', 'x'],
        ]));

        $this->assertSame(ImportStatus::Validated, $batch->status);
    }

    public function test_missing_required_column_rejects_the_file_and_names_the_column(): void
    {
        $batch = $this->validate($this->batch([['Documento', 'Cliente', 'Importe'], ['0001', 'A', 1]]));

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $missing = collect($this->errorsOf($batch, 'missing_column'))->pluck('column')->all();
        $this->assertEqualsCanonicalizing(['Código Producto', 'Fecha'], $missing);
        $this->assertSame(0, $batch->rows_total); // sin encabezados válidos no se leen filas
    }

    public function test_unknown_columns_are_warnings_and_duplicate_columns_are_errors(): void
    {
        $batch = $this->validate($this->batch([[...self::HEADER, 'Color'], ['0001', 'A', 'P', 1, '2026-10-01', 'rojo']]));
        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertSame('Color', $this->errorsOf($batch, 'unknown_column')[0]->column);
        $this->assertSame(1, $batch->warnings_total);

        $dup = $this->validate($this->batch([[...self::HEADER, 'Importe'], ['0001', 'A', 'P', 1, '2026-10-01', 2]]));
        $this->assertSame(ImportStatus::Rejected, $dup->status);
        $this->assertCount(1, $this->errorsOf($dup, 'duplicate_column'));
    }

    public function test_row_errors_report_excel_row_and_column_and_block_the_batch(): void
    {
        $batch = $this->validate($this->batch([
            self::HEADER,
            ['0001', 'A', 'P1', 10, '2026-10-01'],     // fila 2: válida
            [],                                         // fila 3: en blanco, se omite
            ['0002', 'B', 'P1', 'abc', '2026-10-01'],  // fila 4: importe inválido
            ['0003', '', 'P1', 5, '2026-10-01'],       // fila 5: cliente vacío
            ['0004', 'D', 'P1', 5, '31/02/2026'],      // fila 6: fecha imposible
        ]));

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertSame(4, $batch->rows_total);
        $this->assertSame(3, $batch->summary['rows_invalid']);
        $this->assertSame(3, $batch->errors_total);

        $amount = $this->errorsOf($batch, 'invalid_number')[0];
        $this->assertSame([4, 'Importe', 'abc'], [$amount->row_number, $amount->column, $amount->value]);
        $this->assertSame([5, 'Cliente'], [$this->errorsOf($batch, 'required')[0]->row_number, $this->errorsOf($batch, 'required')[0]->column]);
        $this->assertSame(6, $this->errorsOf($batch, 'invalid_date')[0]->row_number);
    }

    public function test_all_errors_of_a_row_are_reported_not_only_the_first(): void
    {
        $batch = $this->validate($this->batch([self::HEADER, ['0001', '', 'P', 'x', 'ayer']]));

        $this->assertSame(3, $batch->errors_total);
    }

    public function test_numeric_codes_warn_about_lost_leading_zeros_but_do_not_block(): void
    {
        $batch = $this->validate($this->batch([self::HEADER, [1, 'A', 77, 5, '2026-10-01']]));

        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertCount(2, $this->errorsOf($batch, 'numeric_code'));
        $this->assertSame(2, $batch->warnings_total);
    }

    public function test_excel_date_cells_and_text_dates_are_accepted(): void
    {
        $batch = $this->validate($this->batch([
            self::HEADER,
            ['0001', 'A', 'P', 1, new \DateTimeImmutable('2026-10-01')],
            ['0002', 'A', 'P', 1, '01/10/2026'],
        ]));

        $this->assertSame(ImportStatus::Validated, $batch->status);
    }

    public function test_lines_of_the_same_document_must_agree_on_document_fields(): void
    {
        $batch = $this->validate($this->batch([
            self::HEADER,
            ['0001', 'Cliente A', 'P1', 10, '2026-10-01'],
            ['0001', 'Cliente B', 'P2', 10, '2026-10-01'],
            ['0001', 'Cliente A', 'P3', 10, '2026-10-05'],
        ]));

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $errors = $this->errorsOf($batch, 'inconsistent_group');
        $this->assertCount(2, $errors);
        $this->assertEqualsCanonicalizing([[3, 'Cliente'], [4, 'Fecha']], collect($errors)->map(fn ($e) => [$e->row_number, $e->column])->all());
    }

    public function test_identical_repeated_line_is_a_warning(): void
    {
        $batch = $this->validate($this->batch([
            self::HEADER,
            ['0001', 'A', 'P1', 10, '2026-10-01'],
            ['0001', 'A', 'P1', 10, '2026-10-01'],
        ]));

        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertSame(3, $this->errorsOf($batch, 'duplicate_row')[0]->row_number);
    }

    public function test_non_excel_file_is_rejected_with_a_data_error(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bad').'.xlsx';
        file_put_contents($path, 'esto no es un excel');

        $batch = $this->validate($this->batchFromFile($path));

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertCount(1, $this->errorsOf($batch, 'unreadable_file'));
    }

    public function test_empty_file_and_header_only_file_are_rejected(): void
    {
        $this->assertCount(1, $this->errorsOf($this->validate($this->batch([[], []])), 'empty_file'));

        $headerOnly = $this->validate($this->batch([self::HEADER]));
        $this->assertSame(ImportStatus::Rejected, $headerOnly->status);
        $this->assertCount(1, $this->errorsOf($headerOnly, 'no_data_rows'));
    }

    public function test_row_limit_is_enforced(): void
    {
        config(['imports.max_rows' => 2]);
        $batch = $this->validate($this->batch([
            self::HEADER,
            ['1', 'A', 'P', 1, '2026-10-01'], ['2', 'A', 'P', 1, '2026-10-01'], ['3', 'A', 'P', 1, '2026-10-01'],
        ]));

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertCount(1, $this->errorsOf($batch, 'too_many_rows'));
        $this->assertSame(2, $batch->rows_total);
    }

    public function test_stored_errors_are_capped_but_the_total_is_complete(): void
    {
        config(['imports.max_stored_errors' => 3]);
        $rows = [self::HEADER];
        for ($i = 1; $i <= 10; $i++) {
            $rows[] = ["D{$i}", 'A', 'P', 'mal', '2026-10-01'];
        }

        $batch = $this->validate($this->batch($rows));

        $this->assertSame(10, $batch->errors_total);
        $this->assertSame(3, $batch->errors()->count());
    }

    public function test_revalidating_replaces_previous_errors(): void
    {
        $batch = $this->batch([self::HEADER, ['0001', 'A', 'P', 'mal', '2026-10-01']]);
        $this->validate($batch);
        $again = $this->validate($batch->fresh());

        $this->assertSame(1, $again->errors()->count());
    }

    public function test_a_file_already_imported_is_rejected(): void
    {
        $path = Xlsx::make([self::HEADER, ['0001', 'A', 'P', 1, '2026-10-01']]);
        $hash = hash_file('sha256', $path);
        ImportBatch::factory()->status(ImportStatus::Imported)->create(['type' => 'fake', 'file_hash' => $hash]);

        $batch = $this->validate($this->batchFromFile($path));

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertCount(1, $this->errorsOf($batch, 'already_imported'));
    }

    public function test_internal_failures_mark_the_batch_failed_without_leaking_details(): void
    {
        FakeImportType::$explode = true;

        $batch = $this->validate($this->batch([self::HEADER, ['0001', 'A', 'P', 1, '2026-10-01']]));

        $this->assertSame(ImportStatus::Failed, $batch->status);
        $this->assertStringNotContainsString('secreto', $batch->failure_message);
    }

    public function test_the_queued_job_validates_the_batch_and_skips_finished_ones(): void
    {
        $batch = $this->batch([self::HEADER, ['0001', 'A', 'P', 1, '2026-10-01']]);

        ValidateImportJob::dispatch($batch->id);
        $this->assertSame(ImportStatus::Validated, $batch->fresh()->status);

        $imported = $this->batch([self::HEADER, ['0001', 'A', 'P', 1, '2026-10-01']], ['status' => ImportStatus::Imported]);
        ValidateImportJob::dispatch($imported->id);
        $this->assertSame(ImportStatus::Imported, $imported->fresh()->status);
    }

    public function test_decimals_are_normalized_to_four_places(): void
    {
        $row = new ImportRow(2, ['a' => '5.25', 'b' => 3, 'c' => 0.1 + 0.2, 'd' => '-0', 'e' => '1.23456', 'f' => '1,5'], new ErrorCollector(10), ['a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E', 'f' => 'F']);

        $this->assertSame('5.2500', $row->decimal('a'));
        $this->assertSame('3.0000', $row->decimal('b'));
        $this->assertSame('0.3000', $row->decimal('c'));
        $this->assertSame('0.0000', $row->decimal('d'));
        $this->assertNull($row->decimal('e')); // texto con demasiados decimales
        $this->assertNull($row->decimal('f')); // coma decimal no aceptada
        $this->assertTrue($row->failed());
    }
}
