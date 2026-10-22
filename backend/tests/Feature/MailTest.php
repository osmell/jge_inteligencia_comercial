<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\MailLog;
use App\Models\MailSetting;
use App\Models\Role;
use App\Models\User;
use App\Services\MailService;
use App\Services\Notifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class MailTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 08:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create(['email' => 'admin@example.com']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return array<string, mixed> */
    private function config(array $over = []): array
    {
        return $over + ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'encryption' => 'starttls', 'username' => 'user', 'password' => 'secreto', 'from_address' => 'no-responder@example.com', 'from_name' => 'JGE'];
    }

    public function test_the_password_is_stored_encrypted_and_never_returned(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $r = $this->putJson('/api/v1/mail/settings', $this->config())->assertOk();

        $this->assertTrue($r->json('data.has_password'));
        $this->assertStringNotContainsString('secreto', $r->getContent());
        $this->assertNotSame('secreto', DB::table('mail_settings')->value('password'));
        $this->assertSame('secreto', MailSetting::current()->password);

        $this->putJson('/api/v1/mail/settings', $this->config(['password' => '', 'port' => 465]))->assertOk();      // vacío = conservar
        $this->assertSame('secreto', MailSetting::current()->password);
        $audit = DB::table('audit_logs')->where('event', 'mail.settings_updated')->get()->pluck('new_values')->implode('|');
        $this->assertStringNotContainsString('secreto', $audit);
    }

    public function test_enabling_needs_a_server_and_a_sender_and_only_administration_configures(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/mail/settings', $this->config(['host' => null]))->assertUnprocessable();
        $this->actingAs(User::factory()->role(Role::GERENTE_GENERAL)->create(), 'sanctum')->getJson('/api/v1/mail/settings')->assertForbidden();
    }

    public function test_a_test_mail_is_sent_and_logged_or_registered_as_not_sent(): void
    {
        Mail::fake();
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/v1/mail/test', ['to' => 'x@example.com'])->assertCreated()->assertJsonPath('data.status', 'not_sent');

        $this->putJson('/api/v1/mail/settings', $this->config())->assertOk();
        $this->postJson('/api/v1/mail/test', ['to' => 'x@example.com'])->assertCreated()->assertJsonPath('data.status', 'sent');
        $this->assertSame(2, MailLog::count());
    }

    public function test_notices_for_people_who_want_email_are_queued_once_and_sent_by_the_dispatcher(): void
    {
        Mail::fake();
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/mail/settings', $this->config())->assertOk();
        $notifier = app(Notifier::class);
        $notifier->send($this->admin, 'digest', 'Resumen', 'Venta: 10', '/resumenes/1', 'digest:1');
        $notifier->send($this->admin, 'digest', 'Resumen', 'Venta: 10', '/resumenes/1', 'digest:1');          // repetido: no duplica
        $notifier->send($this->admin, 'system', 'Otro', null, null, 'sys:1');                                    // sin correo por omisión

        $this->assertSame(1, MailLog::count());
        $log = MailLog::sole();
        $this->assertSame(['pending', 'admin@example.com'], [$log->status, $log->to_address]);
        $this->assertStringEndsWith('/resumenes/1', (string) $log->action_url);

        $this->assertSame(1, app(MailService::class)->dispatchPending()['sent']);
        $this->assertSame(0, app(MailService::class)->dispatchPending()['sent']);
        $this->assertSame('sent', $log->refresh()->status);
    }

    public function test_without_configuration_or_address_the_mail_is_registered_as_not_sent(): void
    {
        $notifier = app(Notifier::class);
        $notifier->send($this->admin, 'digest', 'Resumen', null, null, 'digest:1');
        $nobody = User::factory()->role(Role::ADMINISTRADOR)->create(['email' => null]);
        $notifier->send($nobody, 'digest', 'Resumen', null, null, 'digest:1');

        $this->assertSame(['not_sent', 'not_sent'], MailLog::orderBy('id')->pluck('status')->all());
    }

    public function test_a_person_can_turn_email_off_for_a_type(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/mail/settings', $this->config())->assertOk();
        $this->putJson('/api/v1/notifications/preferences', ['email' => ['digest' => false]])->assertOk();
        app(Notifier::class)->send($this->admin, 'digest', 'Resumen', null, null, 'digest:1');

        $this->assertSame(0, MailLog::count());
    }

    public function test_failures_are_retried_with_backoff_and_then_given_up(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/mail/settings', $this->config(['host' => '127.0.0.1', 'port' => 1]))->assertOk();
        app(Notifier::class)->send($this->admin, 'digest', 'Resumen', null, null, 'digest:1');
        $mail = app(MailService::class);

        $this->assertSame(1, $mail->dispatchPending()['retry']);
        $log = MailLog::sole();
        $this->assertSame(['pending', 1], [$log->status, $log->attempt]);
        $this->assertSame(0, array_sum($mail->dispatchPending()));                 // aún no toca
        Carbon::setTestNow(now()->addMinutes(11));
        $mail->dispatchPending();
        Carbon::setTestNow(now()->addMinutes(61));
        $this->assertSame(1, $mail->dispatchPending()['failed']);
        $this->assertSame(['failed', 3], [$log->refresh()->status, $log->attempt]);
        $this->assertNotEmpty($log->error);
    }
}
