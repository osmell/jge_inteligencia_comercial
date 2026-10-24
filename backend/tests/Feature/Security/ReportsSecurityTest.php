<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Mail\AppMail;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\MailSetting;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\Reports\Output\ReportColumn;
use App\Services\Reports\Output\ReportDocument;
use App\Services\Reports\Output\ReportNotes;
use App\Services\Reports\Output\ReportSection;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSalesFixtures;
use Tests\TestCase;

/**
 * Revisión de seguridad del apartado de Reportes: nadie lee datos de otra compañía por identificador, nada que llega en la consulta
 * altera el SQL, ningún reporte expone costos, todo reporte deja rastro y nada de lo que viene de los archivos se interpreta en las salidas.
 */
class ReportsSecurityTest extends TestCase
{
    use BuildsSalesFixtures, RefreshDatabase;

    private Company $alfa;

    private Company $beta;

    private Branch $centro;

    private Branch $branchBeta;

    private Seller $ana;

    private Seller $beto;

    private Operator $omar;

    private Operator $olga;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['name' => 'Alfa']);
        $this->beta = Company::factory()->create(['name' => 'Beta']);
        $this->centro = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Centro']);
        $this->branchBeta = Branch::factory()->create(['company_id' => $this->beta->id, 'name' => 'Beta Centro']);
        $this->ana = $this->seller($this->alfa, 'Ana');
        $this->beto = $this->seller($this->beta, 'Beto');
        $this->omar = $this->operator($this->alfa, 'Omar');
        $this->olga = $this->operator($this->beta, 'Olga');
        $this->sale($this->ana, $this->omar, $this->centro, '2026-10-05', 'C1', [['net' => 100.0, 'normal' => 120.0, 'price' => 100.0]]);
        $this->sale($this->beto, $this->olga, $this->branchBeta, '2026-10-05', 'C9', [['net' => 9999.0]]);
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->manager->companies()->sync([$this->alfa->id]);
    }

    private const PERIOD = 'from=2026-10-01&to=2026-10-31';

    /** @return array<string, string> todas las consultas de reportes de una compañía, con los identificadores que se le pasen */
    private function endpoints(int $seller, int $operator, int $branch): array
    {
        $p = self::PERIOD;

        return [
            'sellers' => "/api/v1/reports/sellers?$p",
            'sellers/{id}/documents' => "/api/v1/reports/sellers/$seller/documents?$p",
            'operators' => "/api/v1/reports/operators?$p",
            'operators/{id}/documents' => "/api/v1/reports/operators/$operator/documents?$p",
            'operators/{id}/distribution' => "/api/v1/reports/operators/$operator/distribution?$p",
            'branches' => "/api/v1/reports/branches?$p",
            'branches/mix' => "/api/v1/reports/branches/mix?$p",
            'branches/{id}/documents' => "/api/v1/reports/branches/$branch/documents?$p",
            'conciliation' => "/api/v1/reports/conciliation?$p",
            'conciliation/issues' => "/api/v1/reports/conciliation/issues?$p&code=lines_count_mismatch",
        ];
    }

    public function test_the_list_of_checked_endpoints_covers_every_report_route(): void
    {
        $covered = array_map(fn (string $e) => preg_replace('#\{id\}#', '{x}', $e), array_keys($this->endpoints(1, 1, 1)));
        $actual = collect(Route::getRoutes()->getRoutes())
            ->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/reports/') && in_array('GET', $r->methods(), true) && ! str_ends_with($r->uri(), '/export') && $r->uri() !== 'api/v1/reports/context')
            ->map(fn (LaravelRoute $r) => preg_replace('#\{[a-z]+\}#', '{x}', substr($r->uri(), strlen('api/v1/reports/'))))
            ->sort()->values()->all();

        $this->assertEquals(collect($covered)->sort()->values()->all(), $actual, 'Hay una ruta de reportes que esta revisión de seguridad no recorre: agréguela a endpoints().');
    }

    public function test_an_identifier_from_another_company_never_opens_anything(): void
    {
        foreach ($this->endpoints($this->beto->id, $this->olga->id, $this->branchBeta->id) as $label => $url) {
            if (! str_contains($label, '{id}')) {
                continue;
            }
            $this->actingAs($this->manager, 'sanctum')->getJson($url)->assertNotFound();
        }
        foreach ($this->endpoints(999999, 999999, 999999) as $label => $url) {
            if (str_contains($label, '{id}')) {
                $this->actingAs($this->manager, 'sanctum')->getJson($url)->assertNotFound();
            }
        }
    }

    public function test_the_company_filter_cannot_be_used_to_read_another_company(): void
    {
        foreach (['sellers', 'operators', 'branches', 'branches/mix', 'conciliation'] as $report) {
            $data = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/$report?".self::PERIOD.'&company_id='.$this->beta->id)->assertOk()->json('data');
            $this->assertStringNotContainsString('Beto', json_encode($data), $report);
            $this->assertStringNotContainsString('9999', json_encode($data), $report);
        }
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/sellers/export?'.self::PERIOD.'&company_id='.$this->beta->id.'&format=pdf')->assertOk();
    }

    /** @return array<string, array{string}> */
    public static function hostileValues(): array
    {
        return [
            'compañía con SQL' => ['company_id=1 or 1=1'],
            'compañía con punto y coma' => ['company_id=1;drop table users'],
            'compañía como lista' => ['company_id[]=1'],
            'compañía cero' => ['company_id=0'],
            'sucursal negativa' => ['branch_id=-5'],
            'sucursal con comilla' => ["branch_id=1'"],
            'comparación con SQL' => ["compare=previous' or '1'='1"],
            'fecha con SQL' => ["from=2026-10-01'; select 1; --"],
            'página cero' => ['page=0'],
            'página enorme' => ['page=999999999999'],
            'página con SQL' => ['page=1 union select 1'],
        ];
    }

    #[DataProvider('hostileValues')]
    public function test_a_hostile_parameter_is_refused_before_it_reaches_the_database(string $hostile): void
    {
        foreach (['sellers', 'operators', 'branches', 'branches/mix', 'conciliation', "sellers/{$this->ana->id}/documents"] as $report) {
            $query = str_starts_with($hostile, 'from=') ? 'to=2026-10-31&'.$hostile : self::PERIOD.'&'.$hostile;
            $status = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/$report?$query")->getStatusCode();
            // Un valor imposible se rechaza (422): nunca llega a una consulta ni provoca un error del servidor.
            $this->assertSame(422, $status, "{$report} con «{$hostile}» respondió {$status}");
        }
        $this->assertSame(1, User::where('id', $this->manager->id)->count(), 'La tabla de usuarios sigue ahí.');
    }

    public function test_no_report_exposes_costs_margins_or_inventory_values(): void
    {
        $forbidden = ['unit_cost', 'line_cost_total', 'cost', 'costs', 'cost_total', 'margin', 'estimated_margin', 'estimated_margin_pct', 'cost_coverage_pct', 'value', 'inventory_value', 'valuation'];
        $scan = function (mixed $node, string $where) use (&$scan, $forbidden): void {
            if (! is_array($node)) {
                return;
            }
            foreach ($node as $key => $child) {
                $this->assertNotContains($key, $forbidden, "{$where} expone «{$key}» (costos, margen o valuación exigen otros permisos).");
                $scan($child, $where);
            }
        };

        foreach ($this->endpoints($this->ana->id, $this->omar->id, $this->centro->id) as $label => $url) {
            $scan($this->actingAs($this->manager, 'sanctum')->getJson($url)->assertOk()->json(), $label);
        }
    }

    public function test_every_report_query_leaves_a_trace_in_the_audit_log(): void
    {
        foreach ($this->endpoints($this->ana->id, $this->omar->id, $this->centro->id) as $label => $url) {
            $before = AuditLog::where('event', 'report.viewed')->count();

            $this->actingAs($this->manager, 'sanctum')->getJson($url)->assertOk();

            $this->assertSame($before + 1, AuditLog::where('event', 'report.viewed')->count(), "$label no deja rastro en Auditoría.");
        }
        $this->assertSame($this->manager->id, AuditLog::where('event', 'report.viewed')->latest('id')->value('user_id'));
    }

    public function test_every_download_leaves_a_trace_in_the_audit_log(): void
    {
        foreach (['sellers', 'operators', 'branches', 'conciliation'] as $report) {
            $before = AuditLog::where('event', 'report.exported')->count();

            $this->actingAs($this->manager, 'sanctum')->get("/api/v1/reports/$report/export?".self::PERIOD.'&format=xlsx')->assertOk();

            $this->assertSame($before + 1, AuditLog::where('event', 'report.exported')->count(), $report);
        }
    }

    public function test_text_from_the_imported_files_is_escaped_in_the_pdf(): void
    {
        $hostile = '<script>alert(1)</script><img src=x onerror=alert(2)>';
        $document = new ReportDocument(
            'Prueba', 'prueba', ["Filtros: $hostile"], ReportNotes::for([]), [['label' => $hostile, 'value' => 1, 'kind' => ReportColumn::INT]],
            [new ReportSection($hostile, [new ReportColumn('Nombre'), new ReportColumn('Cifra', ReportColumn::INT, true)], [[$hostile, ['value' => 3, 'level' => 'bad']]], $hostile)],
        );

        $html = view('pdf.report', ['document' => $document, 'levels' => ReportNotes::levelLabels(), 'generatedBy' => $hostile, 'generatedAt' => 'hoy'])->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringNotContainsString('<img', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    private function mailReady(): void
    {
        MailSetting::current()->forceFill(['id' => 1, 'enabled' => true, 'host' => 'smtp.prueba.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'reportes@jge.test', 'from_name' => 'JGE'])->save();
    }

    private function email(array $over = []): TestResponse
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/reports/sellers/email', $over + ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'xlsx', 'recipients' => ['jefe@ejemplo.com'], 'confirmed' => true]);
    }

    public function test_an_address_cannot_smuggle_extra_recipients_or_headers(): void
    {
        $this->mailReady();
        Mail::fake();

        foreach (["a@ejemplo.com\r\nBcc: robo@malo.test", "a@ejemplo.com\nCc: robo@malo.test", 'a@ejemplo.com,robo@malo.test,', 'a@ejemplo.com <robo@malo.test>', "a@ejemplo.com\0b@ejemplo.com"] as $address) {
            $response = $this->email(['recipients' => [$address]]);
            $this->assertSame(422, $response->getStatusCode(), 'Se aceptó: '.json_encode($address));
        }

        Mail::assertNothingSent();
    }

    public function test_the_message_text_cannot_add_headers_or_recipients(): void
    {
        $this->mailReady();
        Mail::fake();

        $this->email(['message' => "Hola\r\nBcc: robo@malo.test\r\nSubject: otro"])->assertOk();

        Mail::assertSent(AppMail::class, fn (AppMail $mail): bool => ! $mail->hasBcc('robo@malo.test') && ! $mail->hasCc('robo@malo.test') && ! $mail->hasTo('robo@malo.test'));
    }

    public function test_a_session_without_the_permission_or_with_a_temporary_password_cannot_send_reports(): void
    {
        $this->mailReady();
        Mail::fake();
        $temporary = User::factory()->role(Role::ADMINISTRADOR)->create(['must_change_password' => true]);
        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $seller->companies()->sync([$this->alfa->id]);

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/reports/sellers/email', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'xlsx', 'recipients' => ['x@ejemplo.com'], 'confirmed' => true])->assertForbidden();
        $this->actingAs($temporary, 'sanctum')->postJson('/api/v1/reports/sellers/email', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'xlsx', 'recipients' => ['x@ejemplo.com'], 'confirmed' => true])->assertForbidden()->assertJsonPath('code', 'password_change_required');

        Mail::assertNothingSent();
    }
}
