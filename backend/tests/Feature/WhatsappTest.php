<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WhatsappTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $company = Company::factory()->create();
        $this->customer = Customer::create(['company_id' => $company->id, 'code' => 'C1', 'name' => 'Cliente Uno', 'source' => 'sales', 'whatsapp' => '+503 7000-1234']);
    }

    /** @return array<string, mixed> */
    private function config(array $over = []): array
    {
        return $over + ['enabled' => true, 'phone_number_id' => '1234567890', 'business_account_id' => '999', 'api_version' => 'v21.0', 'access_token' => 'tok-secreto', 'app_secret' => 'app-secreto', 'verify_token' => 'vt-9x2'];
    }

    private function template(string $status = 'approved'): WhatsappTemplate
    {
        return WhatsappTemplate::create(['name' => 'recordatorio_pago', 'language' => 'es', 'category' => 'utility', 'body' => 'Hola {{1}}, tu saldo es {{2}}.', 'status' => $status]);
    }

    private function ready(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/whatsapp/settings', $this->config())->assertOk();
    }

    public function test_credentials_are_encrypted_and_never_returned_or_audited(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $r = $this->putJson('/api/v1/whatsapp/settings', $this->config())->assertOk();

        $this->assertTrue($r->json('data.has_access_token'));
        foreach (['tok-secreto', 'app-secreto', 'vt-9x2'] as $secret) {
            $this->assertStringNotContainsString($secret, $r->getContent());
        }
        $this->assertNotSame('tok-secreto', DB::table('whatsapp_settings')->value('access_token'));
        $this->putJson('/api/v1/whatsapp/settings', $this->config(['access_token' => '', 'api_version' => 'v22.0']))->assertOk();
        $this->assertSame('tok-secreto', WhatsappSetting::current()->access_token);
        $audit = DB::table('audit_logs')->where('event', 'whatsapp.settings_updated')->get()->pluck('new_values')->implode('|');
        $this->assertStringNotContainsString('tok-secreto', $audit);
        $this->putJson('/api/v1/whatsapp/settings', $this->config(['phone_number_id' => null]))->assertUnprocessable();
        $this->actingAs(User::factory()->role(Role::GERENTE_GENERAL)->create(), 'sanctum')->getJson('/api/v1/whatsapp/settings')->assertForbidden();
    }

    public function test_the_connection_test_reports_meta_answer(): void
    {
        $this->ready();
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push(['verified_name' => 'JGE', 'display_phone_number' => '+503 2222-0000'])->push(['error' => ['message' => 'Invalid OAuth access token']], 401)]);
        $this->postJson('/api/v1/whatsapp/test')->assertOk()->assertJsonPath('data.ok', true);
        $this->postJson('/api/v1/whatsapp/test')->assertJsonPath('data.ok', false)->assertJsonFragment(['message' => 'Meta respondió: Invalid OAuth access token']);
    }

    public function test_an_approved_template_is_sent_with_consent_and_only_once_a_day(): void
    {
        $this->ready();
        $t = $this->template();
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.1']]])]);
        $url = "/api/v1/customers/{$this->customer->id}/whatsapp";
        $this->postJson("$url/consent", ['status' => 'granted', 'source' => 'Aceptó por llamada del 20/10'])->assertOk()->assertJsonPath('data.consent', 'granted');

        $r = $this->postJson("$url/send", ['template_id' => $t->id, 'params' => ['Ana', '$50']])->assertCreated();
        $this->assertSame(['sent', 'Hola Ana, tu saldo es $50.', '50370001234'], [$r->json('data.status'), $r->json('data.body'), $r->json('data.to')]);
        Http::assertSent(fn ($req) => $req['to'] === '50370001234' && $req['template']['name'] === 'recordatorio_pago' && $req['template']['components'][0]['parameters'][1]['text'] === '$50');

        $this->postJson("$url/send", ['template_id' => $t->id, 'params' => ['Ana', '$50']])->assertCreated();
        $this->assertSame(1, WhatsappMessage::count());                       // el mismo mensaje el mismo día no se repite
    }

    public function test_nothing_is_sent_without_consent_to_do_not_contact_or_with_unapproved_templates(): void
    {
        $this->ready();
        Http::fake();
        $url = "/api/v1/customers/{$this->customer->id}/whatsapp";
        $approved = $this->template();

        $r = $this->postJson("$url/send", ['template_id' => $approved->id, 'params' => ['Ana', '1']])->assertCreated();
        $this->assertSame('not_sent', $r->json('data.status'));
        $this->assertStringContainsString('consentimiento', $r->json('data.error'));

        $this->postJson("$url/consent", ['status' => 'granted', 'source' => 'Formulario firmado'])->assertOk();
        $this->customer->forceFill(['do_not_contact' => true])->save();
        $this->assertStringContainsString('no contactar', $this->postJson("$url/send", ['template_id' => $approved->id, 'params' => ['Ana', '2']])->json('data.error'));

        $this->customer->forceFill(['do_not_contact' => false])->save();
        $draft = WhatsappTemplate::create(['name' => 'otra', 'language' => 'es', 'category' => 'marketing', 'body' => 'Hola', 'status' => 'pending']);
        $this->assertStringContainsString('no está aprobada', $this->postJson("$url/send", ['template_id' => $draft->id])->json('data.error'));
        $this->assertStringContainsString('Faltan valores', $this->postJson("$url/send", ['template_id' => $approved->id, 'params' => ['Ana']])->json('data.error'));

        $this->postJson("$url/consent", ['status' => 'revoked', 'source' => 'Pidió no recibir más'])->assertOk();
        $this->assertStringContainsString('consentimiento', $this->postJson("$url/send", ['template_id' => $approved->id, 'params' => ['Ana', '3']])->json('data.error'));
        Http::assertNothingSent();
        $this->assertSame(0, WhatsappMessage::where('status', 'sent')->count());
    }

    public function test_meta_webhook_updates_status_with_a_valid_signature_only(): void
    {
        $this->ready();
        $log = WhatsappMessage::create(['customer_id' => $this->customer->id, 'to_number' => '1', 'body' => 'x', 'status' => 'sent', 'wa_message_id' => 'wamid.9', 'created_at' => now()]);
        $payload = json_encode(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.9', 'status' => 'read', 'timestamp' => (string) now()->timestamp]]]]]]]]);
        $sign = 'sha256='.hash_hmac('sha256', (string) $payload, 'app-secreto');
        $send = fn (string $sig) => $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => $sig], (string) $payload);

        Auth::forgetGuards();
        $send('sha256=falsa')->assertForbidden();
        $this->assertSame('sent', $log->refresh()->status);
        $send($sign)->assertOk()->assertJsonPath('applied', 1);
        $this->assertSame('read', $log->refresh()->status);
        $this->assertNotNull($log->delivered_at);
        $send($sign)->assertJsonPath('applied', 0);                           // un estado no retrocede ni se repite

        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=vt-9x2&hub_challenge=abc')->assertOk()->assertSee('abc');
        $this->get('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=otro&hub_challenge=abc')->assertForbidden();
    }

    public function test_templates_are_managed_by_administration_with_valid_names(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/v1/whatsapp/templates', ['name' => 'Mala Plantilla', 'language' => 'es', 'category' => 'utility', 'body' => 'Hola', 'status' => 'draft'])->assertUnprocessable();
        $id = $this->postJson('/api/v1/whatsapp/templates', ['name' => 'bienvenida', 'language' => 'es', 'category' => 'utility', 'body' => 'Hola {{1}} y {{2}}', 'status' => 'draft'])->assertCreated()->assertJsonPath('data.variables', 2)->json('data.id');
        $this->putJson("/api/v1/whatsapp/templates/$id", ['name' => 'bienvenida', 'language' => 'es', 'category' => 'utility', 'body' => 'Hola {{1}}', 'status' => 'approved'])->assertOk()->assertJsonPath('data.status', 'approved');
        $this->getJson('/api/v1/whatsapp/templates')->assertJsonCount(1, 'data');
    }
}
