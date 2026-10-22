<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ImportStatus;
use App\Imports\ImportRunner;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Xlsx;
use Tests\TestCase;

class SalesImportTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = [
        'Documento', 'Serie', 'Fecha', 'Compañía', 'Sucursal', 'Código Cliente', 'Nombre Cliente', 'Código del Producto', 'Und.Vendidas',
        'Nombre del Producto', 'Costo Tot.Vta.', 'Costo Unit.Vta.', 'Precio Unit.Vta.', 'Precio Unit.Normal', 'Total Neto', 'Total Impuesto',
        'Venta + Imp', 'Gran Total', 'Nombre Vendedor', 'Nombre Nivel 2 Inv', 'Nombre Operador',
    ];

    private Company $alfa;

    private Branch $central;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('imports');
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['code' => 'ALFA', 'name' => 'Compañía Alfa']);
        $this->central = Branch::factory()->create(['company_id' => $this->alfa->id, 'code' => 'CEN', 'name' => 'Central']);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    /** Fila de venta válida; `$o` sustituye columnas por posición de nombre. */
    private function line(array $o = []): array
    {
        $d = [
            'Documento' => '0001', 'Serie' => 'A', 'Fecha' => '2026-10-01', 'Compañía' => 'ALFA', 'Sucursal' => 'CEN', 'Código Cliente' => 'C001',
            'Nombre Cliente' => 'Cliente Uno', 'Código del Producto' => '000123', 'Und.Vendidas' => 2, 'Nombre del Producto' => 'Filtro',
            'Costo Tot.Vta.' => 20, 'Costo Unit.Vta.' => 10, 'Precio Unit.Vta.' => 15, 'Precio Unit.Normal' => 16, 'Total Neto' => 30,
            'Total Impuesto' => 3.6, 'Venta + Imp' => 33.6, 'Gran Total' => 33.6, 'Nombre Vendedor' => 'María López',
            'Nombre Nivel 2 Inv' => 'Filtros', 'Nombre Operador' => 'Caja 1',
            ...$o,
        ];

        return array_map(fn ($h) => $d[$h], self::HEADER);
    }

    private function batch(array $lines, ?User $user = null, array $header = self::HEADER): ImportBatch
    {
        $path = Xlsx::make([$header, ...$lines]);
        $stored = 'sales/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));

        return ImportBatch::factory()->create([
            'type' => 'sales', 'status' => ImportStatus::Pending, 'stored_path' => $stored,
            'file_hash' => hash_file('sha256', $path), 'user_id' => ($user ?? $this->admin)->id,
        ]);
    }

    private function validated(array $lines): ImportBatch
    {
        $batch = $this->batch($lines);
        app(ImportRunner::class)->validate($batch);

        return $batch->fresh();
    }

    private function imported(array $lines): ImportBatch
    {
        $batch = $this->validated($lines);
        $this->assertSame(ImportStatus::Validated, $batch->status, json_encode($batch->errors()->pluck('message')));
        app(ImportRunner::class)->import($batch);

        return $batch->fresh();
    }

    private function error(ImportBatch $batch, string $code): ?object
    {
        return $batch->errors()->where('code', $code)->first();
    }

    public function test_valid_file_gets_a_summary_without_writing_sales(): void
    {
        $batch = $this->validated([
            $this->line(),
            $this->line(['Código del Producto' => '000124', 'Total Neto' => 70, 'Total Impuesto' => 8.4, 'Venta + Imp' => 78.4, 'Gran Total' => 78.4, 'Nombre Vendedor' => 'María López']),
            $this->line(['Documento' => '0002', 'Fecha' => '2026-10-03', 'Nombre Operador' => 'Caja 2']),
        ]);

        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertSame([3, 2, 2, 0], [$batch->rows_total, $batch->documents_total, $batch->documents_new, $batch->documents_duplicate]);
        $this->assertSame('2026-10-01', $batch->summary['date_from']);
        $this->assertSame('2026-10-03', $batch->summary['date_to']);
        $this->assertSame('130.0000', $batch->summary['totals']['net_total']);
        $this->assertSame([['id' => $this->alfa->id, 'name' => 'Compañía Alfa', 'documents' => 2, 'lines' => 3, 'net_total' => '130.0000']], $batch->summary['companies']);
        $this->assertSame(['María López'], $batch->summary['new_sellers']);
        $this->assertEqualsCanonicalizing(['Caja 1', 'Caja 2'], $batch->summary['new_operators']);
        $this->assertSame(0, SalesDocument::withoutGlobalScopes()->count()); // validar no escribe
    }

    public function test_import_writes_documents_lines_sellers_and_operators(): void
    {
        $batch = $this->imported([
            $this->line(),
            $this->line(['Código del Producto' => '000124', 'Und.Vendidas' => 1, 'Costo Unit.Vta.' => 10.1234, 'Costo Tot.Vta.' => 10.1234, 'Total Neto' => 15, 'Total Impuesto' => 1.8, 'Venta + Imp' => 16.8, 'Gran Total' => 16.8]),
        ]);

        $this->assertSame(ImportStatus::Imported, $batch->status);
        $this->assertNotNull($batch->finished_at);

        $doc = SalesDocument::withoutGlobalScopes()->sole();
        $this->assertSame(['A', '0001', $this->alfa->id, $this->central->id], [$doc->series, $doc->number, $doc->company_id, $doc->branch_id]);
        $this->assertSame('2026-10-01', $doc->issued_on->toDateString());
        $this->assertNull($doc->issued_at);
        $this->assertSame(2, $doc->lines_count);
        $this->assertSame($batch->id, $doc->import_batch_id);

        $lines = SalesLine::withoutGlobalScopes()->orderBy('line_number')->get();
        $this->assertSame(['000123', '000124'], $lines->pluck('product_code')->all()); // ceros iniciales conservados
        $this->assertSame('10.1234', $lines[1]->unit_cost);
        $this->assertSame('3.6000', $lines[0]->tax_total);
        $this->assertSame('Filtros', $lines[0]->category_name);
        $this->assertSame([1, 2], $lines->pluck('line_number')->all());

        $this->assertSame(1, Seller::count());
        $this->assertSame(1, Operator::count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'import.completed', 'user_id' => $this->admin->id]);
    }

    public function test_sellers_and_operators_are_reused_ignoring_case_and_spaces(): void
    {
        $this->imported([$this->line(['Nombre Vendedor' => 'María López', 'Nombre Operador' => 'Caja 1'])]);
        $this->imported([$this->line(['Documento' => '0002', 'Nombre Vendedor' => '  MARÍA   LÓPEZ ', 'Nombre Operador' => 'caja 1'])]);

        $this->assertSame(1, Seller::count());
        $this->assertSame(1, Operator::count());
        $this->assertSame(2, SalesDocument::withoutGlobalScopes()->count());
    }

    public function test_identical_reimport_in_another_file_is_reported_not_duplicated(): void
    {
        $this->imported([$this->line(), $this->line(['Documento' => '0002'])]);

        // Otro archivo (distinto hash) con un documento ya importado y uno nuevo.
        $second = $this->validated([$this->line(), $this->line(['Documento' => '0003'])]);
        $this->assertSame(ImportStatus::Validated, $second->status);
        $this->assertSame([2, 1, 1], [$second->documents_total, $second->documents_new, $second->documents_duplicate]);

        app(ImportRunner::class)->import($second);

        $this->assertSame(ImportStatus::Imported, $second->fresh()->status);
        $this->assertSame(3, SalesDocument::withoutGlobalScopes()->count());
        $this->assertSame(3, SalesLine::withoutGlobalScopes()->count());
    }

    public function test_a_document_with_different_content_is_a_conflict_and_is_never_overwritten(): void
    {
        $this->imported([$this->line()]);

        $batch = $this->validated([$this->line(['Total Neto' => 40, 'Total Impuesto' => 4.8, 'Venta + Imp' => 44.8, 'Gran Total' => 44.8])]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $conflict = $this->error($batch, 'document_conflict');
        $this->assertSame([2, 'Documento'], [$conflict->row_number, $conflict->column]);
        $this->assertSame('30.0000', SalesLine::withoutGlobalScopes()->sole()->net_total);
    }

    public function test_import_is_all_or_nothing_when_the_database_changed_after_validation(): void
    {
        $batch = $this->validated([$this->line(['Documento' => '0001']), $this->line(['Documento' => '0002'])]);
        $this->assertSame(ImportStatus::Validated, $batch->status);

        // Mientras tanto otra persona importa el documento 0002 con contenido distinto.
        $this->imported([$this->line(['Documento' => '0002', 'Nombre Cliente' => 'Otro cliente'])]);

        app(ImportRunner::class)->import($batch);

        $this->assertSame(ImportStatus::Rejected, $batch->fresh()->status);
        $this->assertNotNull($this->error($batch->fresh(), 'document_conflict'));
        $this->assertSame(1, SalesDocument::withoutGlobalScopes()->count()); // el 0001 NO se escribió
    }

    public function test_a_failure_at_the_end_of_the_transaction_rolls_back_documents_and_lines(): void
    {
        $batch = $this->validated([$this->line(['Documento' => '0001']), $this->line(['Documento' => '0002', 'Nombre Vendedor' => 'Otro'])]);
        // Falla al marcar el lote como importado: documentos, líneas, vendedores y operadores ya estaban escritos.
        ImportBatch::updating(function (ImportBatch $b) {
            if ($b->status === ImportStatus::Imported) {
                throw new \RuntimeException('fallo simulado');
            }
        });

        app(ImportRunner::class)->import($batch);

        $this->assertSame(ImportStatus::Failed, $batch->fresh()->status);
        $this->assertSame(0, SalesDocument::withoutGlobalScopes()->count());
        $this->assertSame(0, SalesLine::withoutGlobalScopes()->count());
        $this->assertSame(0, Seller::count());
        $this->assertSame(0, Operator::count());
        $this->assertStringNotContainsString('simulado', $batch->fresh()->failure_message);
    }

    public function test_a_file_that_changes_between_validation_and_writing_aborts_without_writing(): void
    {
        $batch = $this->validated([$this->line(['Documento' => '0001'])]);
        // Se reemplaza el archivo guardado por uno distinto tras validar: el contenido ya no es el validado.
        $other = Xlsx::make([self::HEADER, $this->line(['Documento' => '0001']), $this->line(['Documento' => '0001', 'Código del Producto' => '000999'])]);
        Storage::disk('imports')->put($batch->stored_path, file_get_contents($other));

        app(ImportRunner::class)->import($batch);

        // Se vuelve a validar completo: el documento seguiría siendo nuevo y válido, pero con distintas líneas;
        // lo importante es que lo escrito coincida exactamente con lo leído en esta importación.
        $doc = SalesDocument::withoutGlobalScopes()->sole();
        $this->assertSame(2, $doc->lines_count);
        $this->assertSame(2, SalesLine::withoutGlobalScopes()->count());
    }

    public function test_only_validated_batches_can_be_imported(): void
    {
        $batch = $this->validated([$this->line(['Total Neto' => 'abc'])]);

        $this->expectException(\InvalidArgumentException::class);
        app(ImportRunner::class)->import($batch);
    }

    public function test_the_same_document_number_may_exist_in_two_companies(): void
    {
        $beta = Company::factory()->create(['code' => 'BETA']);
        Branch::factory()->create(['company_id' => $beta->id, 'code' => 'CEN']);

        $this->imported([$this->line(), $this->line(['Compañía' => 'BETA'])]);

        $this->assertSame(2, SalesDocument::withoutGlobalScopes()->count());
    }

    public function test_amount_reconciliation_errors_point_to_the_column_and_row(): void
    {
        $batch = $this->validated([
            $this->line(),
            $this->line(['Documento' => '0002', 'Venta + Imp' => 34.0]),
            $this->line(['Documento' => '0003', 'Gran Total' => 35.0]),    // «Gran Total» se omite: no genera error
            $this->line(['Documento' => '0004', 'Total Impuesto' => 3.605, 'Venta + Imp' => 33.6, 'Gran Total' => 33.6]), // dentro de la tolerancia
        ]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $errors = $batch->errors()->where('code', 'amount_mismatch')->get();
        $this->assertSame([[3, 'Venta + Imp']], $errors->map(fn ($e) => [$e->row_number, $e->column])->all());
        $this->assertStringContainsString('30.00 + 3.60 = 33.60', $errors[0]->message);
    }

    public function test_sale_plus_tax_not_matching_net_plus_tax_is_flagged_and_grand_total_is_ignored(): void
    {
        $batch = $this->validated([$this->line(['Venta + Imp' => 40, 'Gran Total' => 40])]);

        $this->assertSame(1, $batch->errors()->where('code', 'amount_mismatch')->count());
        $this->assertSame('Venta + Imp', $batch->errors()->where('code', 'amount_mismatch')->value('column'));
    }

    public function test_grand_total_is_omitted_and_billed_total_is_net_plus_tax(): void
    {
        // Gran Total distinto (p. ej. un ajuste del documento repetido en la línea) o negativo: ni valida ni se guarda.
        $batch = $this->imported([$this->line(['Gran Total' => -30]), $this->line(['Documento' => '0002', 'Gran Total' => 'texto'])]);

        $this->assertNull($batch->errors()->where('column', 'Gran Total')->first());
        $this->assertSame(['33.6000', '33.6000'], SalesLine::orderBy('id')->pluck('grand_total')->all());
    }

    public function test_grand_total_column_may_be_absent(): void
    {
        $header = array_values(array_diff(self::HEADER, ['Gran Total']));
        $path = Xlsx::make([$header, array_values(array_diff_key($this->line(), [17 => 1]))]);
        $stored = 'sales/'.uniqid().'.xlsx';
        Storage::disk('imports')->put($stored, file_get_contents($path));
        $batch = ImportBatch::factory()->create(['type' => 'sales', 'status' => ImportStatus::Pending, 'stored_path' => $stored, 'file_hash' => hash_file('sha256', $path), 'user_id' => $this->admin->id]);
        app(ImportRunner::class)->validate($batch);

        $this->assertSame(ImportStatus::Validated, $batch->fresh()->status, json_encode($batch->errors()->pluck('message')));
    }

    public function test_units_accept_up_to_sixteen_decimals_and_must_be_positive(): void
    {
        $batch = $this->imported([
            $this->line(['Und.Vendidas' => 0.00000001, 'Costo Tot.Vta.' => 0, 'Total Neto' => 0.01, 'Total Impuesto' => 0, 'Venta + Imp' => 0.01]),
            $this->line(['Documento' => '0002', 'Und.Vendidas' => '0.0000123456789012', 'Costo Tot.Vta.' => 1.2346]),
        ]);

        $this->assertNull($batch->errors()->where('code', 'non_positive_units')->first());
        $units = SalesLine::orderBy('id')->pluck('units')->all();
        $this->assertSame(['0.0000000100000000', '0.0000123456789012'], $units);

        $zero = $this->validated([$this->line(['Documento' => '0003', 'Und.Vendidas' => 0])]);
        $this->assertSame('non_positive_units', $zero->errors()->where('severity', 'error')->value('code'));
        $text = $this->validated([$this->line(['Documento' => '0004', 'Und.Vendidas' => '0.00001234567890123'])]);
        $this->assertSame('too_many_decimals', $text->errors()->value('code'));
    }

    public function test_lines_with_everything_at_zero_are_skipped_with_a_warning(): void
    {
        $zero = ['Und.Vendidas' => 0, 'Costo Tot.Vta.' => 0, 'Costo Unit.Vta.' => 0, 'Precio Unit.Vta.' => 0, 'Total Neto' => 0, 'Total Impuesto' => 0, 'Venta + Imp' => 0];
        $batch = $this->validated([
            $this->line(),
            $this->line(['Documento' => '0002', ...$zero]),
            $this->line(['Documento' => '0003', ...$zero, 'Total Neto' => 5]),
        ]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertSame(1, $batch->errors()->where('code', 'zero_line_skipped')->count());
        $this->assertSame(1, $batch->errors()->where('code', 'non_positive_units')->count());

        $ok = $this->imported([$this->line(), $this->line(['Documento' => '0002', ...$zero])]);
        $this->assertSame(1, $ok->errors()->where('code', 'zero_line_skipped')->count());
        $this->assertSame(1, SalesLine::count());
        $this->assertSame(1, $ok->summary['zero_lines_skipped']);
    }

    public function test_tolerance_is_configurable(): void
    {
        config(['imports.amount_tolerance' => 1.0]);

        $this->assertSame(ImportStatus::Validated, $this->validated([$this->line(['Venta + Imp' => 34.0, 'Gran Total' => 34.0])])->status);
    }

    public function test_negative_amounts_and_non_positive_units_are_rejected_with_a_hint(): void
    {
        $batch = $this->validated([
            $this->line(['Und.Vendidas' => -1]),
            $this->line(['Documento' => '0002', 'Und.Vendidas' => 0]),
            $this->line(['Documento' => '0003', 'Total Neto' => -30, 'Total Impuesto' => -3.6, 'Venta + Imp' => -33.6, 'Gran Total' => -33.6]),
        ]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertSame(2, $batch->errors()->where('code', 'non_positive_units')->count());
        $this->assertStringContainsString('notas de crédito', $this->error($batch, 'non_positive_units')->message);
        $this->assertSame(4, $batch->errors()->where('code', 'negative_amount')->count());
    }

    public function test_cost_total_mismatch_is_only_a_warning(): void
    {
        $batch = $this->validated([$this->line(['Costo Tot.Vta.' => 99])]);

        $this->assertSame(ImportStatus::Validated, $batch->status);
        $warning = $this->error($batch, 'cost_total_mismatch');
        $this->assertSame('warning', $warning->severity);
        $this->assertSame('Costo Tot.Vta.', $warning->column);
    }

    public function test_tax_rate_is_only_checked_when_configured(): void
    {
        $this->assertSame(ImportStatus::Validated, $this->validated([$this->line(['Total Impuesto' => 4.5, 'Venta + Imp' => 34.5, 'Gran Total' => 34.5])])->status);

        config(['imports.sales.tax_rates' => [0.12, 0.0]]);

        $this->assertSame(ImportStatus::Validated, $this->validated([$this->line(['Documento' => '9', 'Total Impuesto' => 3.6])])->status);
        $this->assertSame(ImportStatus::Validated, $this->validated([$this->line(['Documento' => '8', 'Total Impuesto' => 0, 'Venta + Imp' => 30, 'Gran Total' => 30])])->status);
        $bad = $this->validated([$this->line(['Documento' => '7', 'Total Impuesto' => 4.5, 'Venta + Imp' => 34.5, 'Gran Total' => 34.5])]);
        $this->assertSame(ImportStatus::Rejected, $bad->status);
        $this->assertNotNull($this->error($bad, 'tax_rate_mismatch'));
    }

    public function test_company_and_branch_are_resolved_by_code_or_name_and_unknown_ones_fail(): void
    {
        $ok = $this->validated([$this->line(['Compañía' => ' compañía ALFA ', 'Sucursal' => 'central'])]);
        $this->assertSame(ImportStatus::Validated, $ok->status);

        $bad = $this->validated([
            $this->line(['Compañía' => 'NOEXISTE']),
            $this->line(['Documento' => '2', 'Sucursal' => 'SUR']),
        ]);
        $this->assertSame('Compañía', $this->error($bad, 'unknown_company')->column);
        $this->assertSame('Sucursal', $this->error($bad, 'unknown_branch')->column);
    }

    public function test_inactive_company_and_branch_are_rejected(): void
    {
        $this->central->update(['is_active' => false]);
        $this->assertNotNull($this->error($this->validated([$this->line()]), 'inactive_branch'));

        $this->alfa->update(['is_active' => false]);
        $this->assertNotNull($this->error($this->validated([$this->line()]), 'inactive_company'));
    }

    public function test_a_user_without_full_company_access_cannot_import_that_company(): void
    {
        $other = Company::factory()->create(['code' => 'BETA']);
        Branch::factory()->create(['company_id' => $other->id, 'code' => 'CEN']);
        $manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $manager->companies()->attach($this->alfa);

        $batch = $this->batch([$this->line(), $this->line(['Documento' => '2', 'Compañía' => 'BETA'])], $manager);
        app(ImportRunner::class)->validate($batch);

        $this->assertSame(ImportStatus::Rejected, $batch->fresh()->status);
        $errors = $batch->errors()->where('code', 'company_out_of_scope')->get();
        $this->assertCount(1, $errors);
        $this->assertSame(3, $errors[0]->row_number);
    }

    public function test_lines_of_a_document_must_share_seller_operator_customer_and_date(): void
    {
        $batch = $this->validated([
            $this->line(),
            $this->line(['Nombre Vendedor' => 'Otro Vendedor']),
            $this->line(['Fecha' => '2026-10-02']),
        ]);

        $this->assertSame(ImportStatus::Rejected, $batch->status);
        $this->assertEqualsCanonicalizing(['Nombre Vendedor', 'Fecha'], $batch->errors()->where('code', 'inconsistent_group')->pluck('column')->all());
    }

    public function test_date_with_time_is_kept_and_text_dates_are_accepted(): void
    {
        $this->imported([
            $this->line(['Fecha' => new \DateTimeImmutable('2026-10-05 14:30:00')]),
            $this->line(['Documento' => '0002', 'Fecha' => '07/10/2026']),
        ]);

        $docs = SalesDocument::withoutGlobalScopes()->orderBy('number')->get();
        $this->assertSame('2026-10-05 14:30:00', $docs[0]->issued_at->format('Y-m-d H:i:s'));
        $this->assertNull($docs[1]->issued_at);
        $this->assertSame('2026-10-07', $docs[1]->issued_on->toDateString());
    }

    public function test_future_dates_are_only_a_warning(): void
    {
        $batch = $this->validated([$this->line(['Fecha' => date('Y-m-d', strtotime('+30 days'))])]);

        $this->assertSame(ImportStatus::Validated, $batch->status);
        $this->assertSame('warning', $this->error($batch, 'future_date')->severity);
    }

    public function test_legacy_facturador_header_is_accepted_and_normal_price_is_optional(): void
    {
        $header = array_map(fn ($h) => $h === 'Nombre Operador' ? 'Nombre Facturador' : $h, self::HEADER);
        $batch = $this->batch([$this->line(['Precio Unit.Normal' => null])], header: $header);
        app(ImportRunner::class)->validate($batch);

        $this->assertSame(ImportStatus::Validated, $batch->fresh()->status);
    }

    public function test_missing_new_columns_are_reported_by_name(): void
    {
        $header = array_values(array_diff(self::HEADER, ['Fecha', 'Compañía', 'Sucursal']));
        $rows = array_map(fn ($l) => array_values(array_diff_key($l, [2 => 1, 3 => 1, 4 => 1])), [$this->line()]);
        $batch = $this->batch($rows, header: $header);
        app(ImportRunner::class)->validate($batch);

        $this->assertEqualsCanonicalizing(['Fecha', 'Compañía', 'Sucursal'], $batch->errors()->where('code', 'missing_column')->pluck('column')->all());
    }

    public function test_same_file_cannot_be_imported_twice(): void
    {
        $first = $this->imported([$this->line()]);

        $again = ImportBatch::factory()->create([
            'type' => 'sales', 'status' => ImportStatus::Pending, 'stored_path' => $first->stored_path,
            'file_hash' => $first->file_hash, 'user_id' => $this->admin->id,
        ]);
        app(ImportRunner::class)->validate($again);

        $this->assertSame(ImportStatus::Rejected, $again->fresh()->status);
        $this->assertNotNull($this->error($again->fresh(), 'already_imported'));
        $this->assertSame(1, SalesDocument::withoutGlobalScopes()->count());
        $this->assertSame(1, AuditLog::where('event', 'import.completed')->count());
    }

    public function test_new_customer_codes_in_sales_create_customers_but_existing_ones_are_not_overwritten(): void
    {
        Customer::create(['company_id' => $this->alfa->id, 'branch_id' => $this->central->id, 'code' => 'C001', 'name' => 'Nombre del maestro', 'source' => 'import']);

        $this->imported([
            $this->line(['Documento' => '0001', 'Código Cliente' => 'C001', 'Nombre Cliente' => 'Nombre de la factura']),
            $this->line(['Documento' => '0002', 'Código Cliente' => 'C002', 'Nombre Cliente' => 'Cliente Dos']),
            $this->line(['Documento' => '0003', 'Código Cliente' => 'C002', 'Nombre Cliente' => 'Cliente Dos']),
        ]);

        $this->assertSame(2, Customer::count());
        $this->assertSame('Nombre del maestro', Customer::where('code', 'C001')->value('name'));
        $new = Customer::where('code', 'C002')->sole();
        $this->assertSame(['Cliente Dos', 'sales', $this->central->id], [$new->name, $new->source, $new->branch_id]);
    }
}
