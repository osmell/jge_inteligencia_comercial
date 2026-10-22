<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookSetting;
use App\Services\Notifier;
use App\Services\WebhookService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WebhookTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'secreto-de-prueba-123456';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 08:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function configure(array $over = []): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/webhook/settings', $over + ['enabled' => true, 'url' => 'https://n8n.example.com/webhook/jge', 'secret' => self::SECRET, 'events' => ['digest', 'followup_due']])->assertOk();
    }

    public function test_the_secret_is_encrypted_and_never_returned_or_audited(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $r = $this->putJson('/api/v1/webhook/settings', ['enabled' => true, 'url' => 'https://x.example.com/h', 'secret' => self::SECRET, 'events' => ['digest']])->assertOk();

        $this->assertTrue($r->json('data.has_secret'));
        $this->assertStringNotContainsString(self::SECRET, $r->getContent());
        $this->assertNotSame(self::SECRET, DB::table('webhook_settings')->value('secret'));
        $this->putJson('/api/v1/webhook/settings', ['enabled' => true, 'url' => 'https://x.example.com/h2', 'secret' => '', 'events' => ['digest']])->assertOk();
        $this->assertSame(self::SECRET, WebhookSetting::current()->secret);
        $this->assertStringNotContainsString(self::SECRET, DB::table('audit_logs')->where('event', 'webhook.settings_updated')->get()->pluck('new_values')->implode('|'));

        $this->putJson('/api/v1/webhook/settings', ['enabled' => true, 'url' => 'no es url', 'events' => []])->assertUnprocessable();
        $this->putJson('/api/v1/webhook/settings', ['enabled' => false, 'url' => null, 'secret' => 'corto', 'events' => []])->assertUnprocessable();
        $this->putJson('/api/v1/webhook/settings', ['enabled' => true, 'url' => 'https://x.example.com/h', 'events' => ['inventado']])->assertUnprocessable();
        $this->actingAs(User::factory()->role(Role::GERENTE_GENERAL)->create(), 'sanctum')->getJson('/api/v1/webhook/settings')->assertForbidden();
    }

    public function test_chosen_events_are_queued_once_and_sent_with_a_valid_signature(): void
    {
        Http::fake(['n8n.example.com/*' => Http::response(['ok' => true])]);
        $this->configure();
        $notifier = app(Notifier::class);
        $company = Company::factory()->create()->id;
        $other = User::factory()->role(Role::ADMINISTRADOR)->create();
        $notifier->send($this->admin, 'digest', 'Resumen diario', 'Venta: 10', '/resumenes/3', 'digest:3', 'info', $company);
        $notifier->send($other, 'digest', 'Resumen diario', 'Venta: 10', '/resumenes/3', 'digest:3', 'info', $company);     // otro destinatario, mismo evento
        $notifier->send($this->admin, 'system', 'No elegido', null, null, 'sys:1');

        $this->assertSame(1, WebhookDelivery::count());
        $this->assertSame(['sent' => 1, 'failed' => 0, 'retry' => 0, 'stale' => 0], app(WebhookService::class)->dispatchPending());
        $this->assertSame(0, app(WebhookService::class)->dispatchPending()['sent']);

        Http::assertSent(function ($req) use ($company) {
            $time = $req->header('X-JGE-Timestamp')[0];
            $expected = 'sha256='.hash_hmac('sha256', $time.'.'.$req->body(), self::SECRET);
            $body = json_decode($req->body(), true);

            return $req->header('X-JGE-Signature')[0] === $expected && $req->header('X-JGE-Event')[0] === 'digest'
                && $body['event'] === 'digest' && $body['title'] === 'Resumen diario' && $body['company_id'] === $company && str_ends_with($body['url'], '/resumenes/3');
        });
    }

    public function test_nothing_is_queued_while_the_webhook_is_off(): void
    {
        $this->configure(['enabled' => false]);
        app(Notifier::class)->send($this->admin, 'digest', 'Resumen', null, null, 'digest:1');

        $this->assertSame(0, WebhookDelivery::count());
    }

    public function test_failures_are_retried_with_backoff_and_then_given_up(): void
    {
        Http::fake(['n8n.example.com/*' => Http::response('boom', 500)]);
        $this->configure();
        app(Notifier::class)->send($this->admin, 'digest', 'Resumen', null, null, 'digest:1');
        $svc = app(WebhookService::class);

        $this->assertSame(1, $svc->dispatchPending()['retry']);
        $this->assertSame(0, array_sum($svc->dispatchPending()));                    // aún no toca
        Carbon::setTestNow(now()->addMinutes(11));
        $svc->dispatchPending();
        Carbon::setTestNow(now()->addMinutes(61));
        $this->assertSame(1, $svc->dispatchPending()['failed']);
        $d = WebhookDelivery::sole();
        $this->assertSame(['failed', 3, 500], [$d->status, $d->attempt, $d->http_status]);
        $this->assertStringContainsString('500', (string) $d->error);
    }

    public function test_the_test_send_is_immediate_and_logged(): void
    {
        Http::fake(['n8n.example.com/*' => Http::response([], 200)]);
        $this->configure();

        $this->postJson('/api/v1/webhook/test')->assertCreated()->assertJsonPath('data.status', 'sent')->assertJsonPath('data.event', 'test');
        $this->getJson('/api/v1/webhook/log')->assertJsonCount(1, 'data');
        $this->configure(['enabled' => false]);
        $this->postJson('/api/v1/webhook/test')->assertUnprocessable();
    }
}
