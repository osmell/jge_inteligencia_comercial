<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Mail\AppMail;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\SellerGoal;
use App\Models\User;
use App\Services\Reports\Output\ReportColumn;
use App\Services\Reports\Output\ReportPdf;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSalesFixtures;
use Tests\TestCase;
use ZipArchive;

/** Salidas de los reportes: Excel, PDF y correo con adjunto, con su alcance, su límite y su rastro en Auditoría. */
class ReportOutputTest extends TestCase
{
    use BuildsSalesFixtures, RefreshDatabase;

    private const REPORTS = ['sellers', 'operators', 'branches', 'conciliation', 'products'];

    private Company $alfa;

    private Company $beta;

    private Branch $centro;

    private Operator $operator;

    private Seller $ana;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-25 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['name' => 'Alfa']);
        $this->beta = Company::factory()->create(['name' => 'Beta']);
        $this->centro = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Centro']);
        $this->operator = $this->operator($this->alfa, 'Omar');
        $this->ana = $this->seller($this->alfa, 'Ana');
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create(['name' => 'Gerente Uno']);
        $this->manager->companies()->sync([$this->alfa->id]);
        SellerGoal::create(['seller_id' => $this->ana->id, 'month' => '2026-10-01', 'amount' => 2000]);
        $this->sale($this->ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0], ['net' => 400.0, 'normal' => 500.0, 'price' => 400.0]]);
        $this->sale($this->ana, $this->operator, $this->centro, '2026-09-15', 'C1', [['net' => 1000.0]]);
        config(['reports.email_per_hour' => 30]);
    }

    private function params(array $extra = []): string
    {
        return http_build_query($extra + ['from' => '2026-10-01', 'to' => '2026-10-31']);
    }

    /** Todo el texto de un Excel: sus hojas y sus cadenas. */
    private function workbookText(string $content): string
    {
        $path = tempnam(sys_get_temp_dir(), 'xl');
        file_put_contents($path, $content);
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($path) === true, 'El archivo no es un Excel válido.');
        $text = '';
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_starts_with($name, 'xl/')) {
                $text .= "\n".$zip->getFromIndex($i);
            }
        }
        $zip->close();
        @unlink($path);

        return $text;
    }

    public function test_every_report_downloads_as_a_valid_excel_with_its_figures_as_numbers(): void
    {
        foreach (self::REPORTS as $report) {
            $response = $this->actingAs($this->manager, 'sanctum')->get("/api/v1/reports/$report/export?".$this->params(['format' => 'xlsx']));

            $response->assertOk()->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $this->assertStringContainsString('filename="', (string) $response->headers->get('Content-Disposition'));
            $text = $this->workbookText($response->getContent());
            $this->assertStringContainsString('Resumen', $text, $report);
            $this->assertStringContainsString('Generado por', $text);
            $this->assertStringContainsString('Gerente Uno', $text);
        }
    }

    public function test_the_sellers_excel_has_the_data_the_screen_shows(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')->get('/api/v1/reports/sellers/export?'.$this->params(['format' => 'xlsx']));
        $text = $this->workbookText($response->getContent());

        $this->assertStringContainsString('Evaluación de vendedores', $text);
        $this->assertStringContainsString('Ana', $text);
        $this->assertStringContainsString('<v>1000</v>', $text, 'La venta neta del periodo sale como número, no como texto.');
        $this->assertStringContainsString('Cumplimiento de meta · nivel', $text, 'Cada semáforo lleva su nivel escrito.');
        $this->assertStringContainsString('Periodo: 2026-10-01 a 2026-10-31', $text);
        $this->assertStringContainsString('Toda cifra de venta es neta', $text);
        $this->assertStringNotContainsString('Beto', $text);
    }

    public function test_the_file_name_carries_the_report_and_the_period(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')->get('/api/v1/reports/operators/export?'.$this->params(['format' => 'pdf']));

        $this->assertStringContainsString('filename="reporte-operadores-2026-10-01_2026-10-31.pdf"', (string) $response->headers->get('Content-Disposition'));
    }

    public function test_the_browser_is_allowed_to_read_the_file_name_from_another_origin(): void
    {
        $response = $this->withHeader('Origin', (string) config('cors.allowed_origins.0'))->actingAs($this->manager, 'sanctum')->get('/api/v1/reports/sellers/export?'.$this->params(['format' => 'pdf']));

        $response->assertOk();
        $this->assertStringContainsString('Content-Disposition', (string) $response->headers->get('Access-Control-Expose-Headers'));
    }

    public function test_every_report_downloads_as_a_pdf(): void
    {
        foreach (self::REPORTS as $report) {
            $response = $this->actingAs($this->manager, 'sanctum')->get("/api/v1/reports/$report/export?".$this->params(['format' => 'pdf']));

            $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
            $this->assertStringStartsWith('%PDF', $response->getContent(), $report);
            $this->assertGreaterThan(1500, strlen($response->getContent()));
        }
    }

    public function test_text_that_looks_like_a_formula_is_neutralised_in_the_excel(): void
    {
        $this->seller($this->alfa, '=HYPERLINK("http://malo.example","clic")');
        $evil = Seller::where('name', 'like', '=HYPERLINK%')->firstOrFail();
        $this->sale($evil, $this->operator, $this->centro, '2026-10-06', 'C9', [['net' => 10.0]]);

        $text = $this->workbookText($this->actingAs($this->manager, 'sanctum')->get('/api/v1/reports/sellers/export?'.$this->params(['format' => 'xlsx']))->getContent());

        $this->assertStringNotContainsString('>=HYPERLINK', $text);
        $this->assertStringContainsString("'=HYPERLINK", $text);
    }

    public function test_the_export_respects_the_company_scope(): void
    {
        $branchBeta = Branch::factory()->create(['company_id' => $this->beta->id]);
        $beto = $this->seller($this->beta, 'Beto Secreto');
        $this->sale($beto, $this->operator($this->beta, 'Olga'), $branchBeta, '2026-10-05', 'C1', [['net' => 9999.0]]);

        foreach (['sellers', 'operators'] as $report) {
            $text = $this->workbookText($this->actingAs($this->manager, 'sanctum')->get("/api/v1/reports/$report/export?".$this->params(['format' => 'xlsx']))->getContent());
            $this->assertStringNotContainsString('Beto Secreto', $text);
            $this->assertStringNotContainsString('Olga', $text);
            $this->assertStringNotContainsString('9999', $text);
        }
    }

    public function test_the_export_follows_the_screen_filters(): void
    {
        $norte = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Norte']);
        $luis = $this->seller($this->alfa, 'Luis Norte');
        $this->sale($luis, $this->operator, $norte, '2026-10-07', 'C2', [['net' => 77.0]]);

        $text = $this->workbookText($this->actingAs($this->manager, 'sanctum')->get('/api/v1/reports/sellers/export?'.$this->params(['format' => 'xlsx', 'branch_id' => $norte->id]))->getContent());

        $this->assertStringContainsString('Luis Norte', $text);
        $this->assertStringNotContainsString('<t>Ana</t>', $text);
        $this->assertStringContainsString('Sucursal: Norte', $text);
    }

    public function test_the_pdf_formatter_writes_spanish_numbers(): void
    {
        $this->assertSame('1,234.50', ReportPdf::format(1234.5, ReportColumn::MONEY));
        $this->assertSame('12.5 %', ReportPdf::format(12.5, ReportColumn::PCT));
        $this->assertSame('+12.5 %', ReportPdf::format(12.5, ReportColumn::SIGNED_PCT));
        $this->assertSame('-3.0 %', ReportPdf::format(-3, ReportColumn::SIGNED_PCT));
        $this->assertSame('1,200', ReportPdf::format(1200, ReportColumn::INT));
        $this->assertSame('—', ReportPdf::format(null, ReportColumn::MONEY));
        $this->assertSame('no comparable', ReportPdf::format('no comparable', ReportColumn::MONEY));
        $this->assertSame('sin usuario', ReportPdf::format('sin usuario', ReportColumn::TEXT));
    }

    public function test_exporting_is_audited_and_validated_and_closed_to_sellers(): void
    {
        $this->actingAs($this->manager, 'sanctum')->get('/api/v1/reports/sellers/export?'.$this->params(['format' => 'pdf']))->assertOk();
        $log = AuditLog::where('event', 'report.exported')->firstOrFail();
        $this->assertSame('sellers', $log->new_values['report']);
        $this->assertSame('pdf', $log->new_values['format']);
        $this->assertSame($this->manager->id, $log->user_id);

        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/sellers/export?'.$this->params(['format' => 'docx']))->assertStatus(422)->assertJsonValidationErrors('format');
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/sellers/export?from=2026-10-01&to=2026-10-31')->assertStatus(422);
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/inventado/export?'.$this->params(['format' => 'pdf']))->assertNotFound();

        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $seller->companies()->sync([$this->alfa->id]);
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/reports/sellers/export?'.$this->params(['format' => 'pdf']))->assertForbidden();
    }

    private function mailReady(): void
    {
        MailSetting::current()->forceFill(['id' => 1, 'enabled' => true, 'host' => 'smtp.prueba.test', 'port' => 587, 'encryption' => 'tls', 'from_address' => 'reportes@jge.test', 'from_name' => 'JGE'])->save();
    }

    private function email(array $body = []): TestResponse
    {
        return $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/reports/sellers/email', $body + [
            'from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'xlsx', 'recipients' => ['jefe@ejemplo.com'], 'confirmed' => true,
        ]);
    }

    public function test_it_sends_one_email_per_recipient_with_the_report_attached(): void
    {
        $this->mailReady();
        Mail::fake();

        $response = $this->email(['recipients' => ['Jefe@Ejemplo.com', 'jefe@ejemplo.com', 'dueno@ejemplo.com'], 'message' => 'Para la reunión del lunes.'])->assertOk();

        $this->assertSame(2, $response->json('data.sent'), 'Las direcciones repetidas (aunque cambien las mayúsculas) se envían una sola vez.');
        $this->assertSame(0, $response->json('data.failed'));
        Mail::assertSent(AppMail::class, 2);
        Mail::assertSent(AppMail::class, function (AppMail $mail): bool {
            $attachment = $mail->attachments()[0];

            return $mail->hasTo('jefe@ejemplo.com') && $attachment->as === 'reporte-vendedores-2026-10-01_2026-10-31.xlsx' && ! $mail->hasTo('dueno@ejemplo.com');
        });
        $logs = MailLog::where('kind', 'report')->orderBy('id')->get();
        $this->assertSame(['jefe@ejemplo.com', 'dueno@ejemplo.com'], $logs->pluck('to_address')->all());
        $this->assertSame([MailLog::SENT, MailLog::SENT], $logs->pluck('status')->all());
        $this->assertStringContainsString('Para la reunión del lunes.', $logs[0]->body);
        $this->assertStringContainsString('Gerente Uno te comparte el reporte', $logs[0]->body);
    }

    public function test_the_recipients_are_left_in_the_audit_trail(): void
    {
        $this->mailReady();
        Mail::fake();

        $this->email(['recipients' => ['jefe@ejemplo.com', 'dueno@ejemplo.com'], 'company_id' => $this->alfa->id])->assertOk();

        $log = AuditLog::where('event', 'report.emailed')->firstOrFail();
        $this->assertSame(['jefe@ejemplo.com', 'dueno@ejemplo.com'], $log->new_values['recipients']);
        $this->assertSame('xlsx', $log->new_values['format']);
        $this->assertSame($this->alfa->id, $log->company_id);
        $this->assertSame(['jefe@ejemplo.com' => 'sent', 'dueno@ejemplo.com' => 'sent'], $log->new_values['filters']['results']);
    }

    public function test_it_will_not_send_without_the_explicit_confirmation_of_the_recipients(): void
    {
        $this->mailReady();
        Mail::fake();

        $this->email(['confirmed' => false])->assertStatus(422)->assertJsonValidationErrors('confirmed');
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/reports/sellers/email', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'xlsx', 'recipients' => ['jefe@ejemplo.com']])
            ->assertStatus(422)->assertJsonValidationErrors('confirmed');

        Mail::assertNothingSent();
        $this->assertSame(0, AuditLog::where('event', 'report.emailed')->count());
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function badRecipients(): array
    {
        return [
            'sin destinatarios' => [['recipients' => []], 'recipients'],
            'dirección inválida' => [['recipients' => ['no-es-correo']], 'recipients.0'],
            'más de diez' => [['recipients' => array_map(fn (int $i) => "p$i@ejemplo.com", range(1, 11))], 'recipients'],
            'mensaje demasiado largo' => [['message' => str_repeat('x', 1001)], 'message'],
            'formato desconocido' => [['format' => 'docx'], 'format'],
        ];
    }

    /** @param  array<string, mixed>  $body */
    #[DataProvider('badRecipients')]
    public function test_invalid_requests_send_nothing(array $body, string $field): void
    {
        $this->mailReady();
        Mail::fake();

        $this->email($body)->assertStatus(422)->assertJsonValidationErrors($field);

        Mail::assertNothingSent();
    }

    public function test_without_configured_mail_nothing_is_attempted_and_no_quota_is_spent(): void
    {
        Mail::fake();
        config(['reports.email_per_hour' => 2]);

        $this->email()->assertStatus(422)->assertJsonValidationErrors('recipients');
        $this->email()->assertStatus(422);

        Mail::assertNothingSent();
        $this->assertSame(0, MailLog::where('kind', 'report')->count());
        $this->mailReady();
        $this->email(['recipients' => ['a@ejemplo.com', 'b@ejemplo.com']])->assertOk();   // el cupo seguía completo
    }

    public function test_the_hourly_limit_counts_every_recipient(): void
    {
        $this->mailReady();
        Mail::fake();
        config(['reports.email_per_hour' => 3]);

        $this->email(['recipients' => ['a@ejemplo.com', 'b@ejemplo.com']])->assertOk();
        $this->email(['recipients' => ['c@ejemplo.com', 'd@ejemplo.com']])->assertStatus(429)->assertJsonPath('errors.recipients.0', 'Solo puedes enviar 1 correo(s) más de reportes en esta hora (máximo 3).');
        $this->email(['recipients' => ['c@ejemplo.com']])->assertOk();
        $this->email(['recipients' => ['d@ejemplo.com']])->assertStatus(429)->assertJsonPath('errors.recipients.0', 'Alcanzaste el máximo de 3 correos de reportes por hora. Inténtalo más tarde.');

        Mail::assertSent(AppMail::class, 3);
    }

    public function test_the_limit_is_per_person(): void
    {
        $this->mailReady();
        Mail::fake();
        config(['reports.email_per_hour' => 1]);
        $other = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $other->companies()->sync([$this->alfa->id]);

        $this->email()->assertOk();
        $this->email()->assertStatus(429);
        $this->actingAs($other, 'sanctum')->postJson('/api/v1/reports/sellers/email', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'pdf', 'recipients' => ['x@ejemplo.com'], 'confirmed' => true])->assertOk();
    }

    public function test_a_failed_delivery_is_reported_as_failed_and_logged(): void
    {
        $this->mailReady();
        Mail::shouldReceive('mailer->to->send')->andThrow(new \RuntimeException('SMTP caído'));

        $response = $this->email(['recipients' => ['jefe@ejemplo.com', 'dueno@ejemplo.com']])->assertOk();

        $this->assertSame(0, $response->json('data.sent'));
        $this->assertSame(2, $response->json('data.failed'));
        $this->assertSame('SMTP caído', $response->json('data.results.0.error'));
        $this->assertSame([MailLog::FAILED, MailLog::FAILED], MailLog::where('kind', 'report')->pluck('status')->all());
        $this->assertSame(['jefe@ejemplo.com' => 'failed', 'dueno@ejemplo.com' => 'failed'], AuditLog::where('event', 'report.emailed')->firstOrFail()->new_values['filters']['results']);
    }

    public function test_sellers_and_anonymous_callers_cannot_email_reports(): void
    {
        $this->mailReady();
        Mail::fake();
        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $seller->companies()->sync([$this->alfa->id]);

        $this->actingAs($seller, 'sanctum')->postJson('/api/v1/reports/sellers/email', ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'xlsx', 'recipients' => ['x@ejemplo.com'], 'confirmed' => true])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/reports/sellers/email', [])->assertUnauthorized();

        Mail::assertNothingSent();
    }

    public function test_every_report_can_be_emailed_as_pdf_too(): void
    {
        $this->mailReady();
        Mail::fake();

        foreach (self::REPORTS as $report) {
            $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/reports/$report/email", ['from' => '2026-10-01', 'to' => '2026-10-31', 'format' => 'pdf', 'recipients' => ['jefe@ejemplo.com'], 'confirmed' => true])->assertOk()->assertJsonPath('data.sent', 1);
        }

        Mail::assertSent(AppMail::class, count(self::REPORTS));
    }
}
