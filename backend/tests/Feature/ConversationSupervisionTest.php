<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Services\ConversationAway;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationSupervisionTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private User $vera;

    private User $beto;

    private Customer $cv;

    private Customer $newCustomer;

    private Seller $sBeto;

    private WhatsappConversation $conv;

    private WhatsappConversation $unknown;

    private WhatsappConversation $betoConv;

    private int $sent = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $this->vera = User::factory()->role(Role::VENDEDOR)->create();
        $this->beto = User::factory()->role(Role::VENDEDOR)->create();
        $a = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        foreach ([$this->manager, $this->supervisor, $this->vera, $this->beto] as $u) {
            $u->companies()->attach($a->id);
        }
        $sVera = Seller::create(['company_id' => $a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vera->id, 'supervisor_id' => $this->supervisor->id]);
        $sBeto = Seller::create(['company_id' => $a->id, 'name' => 'Beto', 'normalized_name' => 'beto', 'user_id' => $this->beto->id]);
        $this->cv = Customer::create(['company_id' => $a->id, 'code' => 'CV', 'name' => 'Cliente Vera', 'source' => 'sales', 'seller_id' => $sVera->id, 'whatsapp' => '+503 7000-0001']);
        $cb = Customer::create(['company_id' => $a->id, 'code' => 'CB', 'name' => 'Cliente Beto', 'source' => 'sales', 'seller_id' => $sBeto->id, 'whatsapp' => '+503 7000-0002']);
        $this->conv = WhatsappConversation::create(['company_id' => $a->id, 'customer_id' => $this->cv->id, 'wa_number' => '50370000001', 'created_at' => now(), 'last_message_at' => now(), 'last_inbound_at' => now()]);
        $this->unknown = WhatsappConversation::create(['company_id' => $a->id, 'customer_id' => null, 'wa_number' => '50370009999', 'created_at' => now()]);
        $this->betoConv = WhatsappConversation::create(['company_id' => $a->id, 'customer_id' => $cb->id, 'wa_number' => '50370000002', 'created_at' => now(), 'last_inbound_at' => now()]);

        $this->newCustomer = Customer::create(['company_id' => $a->id, 'code' => 'CN', 'name' => 'Cliente Nuevo', 'source' => 'sales', 'seller_id' => $sVera->id]);
        $this->sBeto = $sBeto;
        Storage::fake('conversations');
        Storage::fake('quotations');
        WhatsappSetting::current()->forceFill(['enabled' => true, 'phone_number_id' => '111222', 'access_token' => 'tok'])->save();

        $perms = ['conversations.view'];
        foreach ([Role::GERENTE_GENERAL => $perms, Role::SUPERVISOR_VENTAS => [...$perms, 'conversations.reply'], Role::VENDEDOR => [...$perms, 'conversations.reply']] as $slug => $list) {
            $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', $slug)->value('id'), ['permissions' => $list])->assertOk();
        }
        Auth::forgetGuards();
    }

    private function png(string $name): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg=='));
    }

    private function metaOk(): void
    {
        Http::fake(['graph.facebook.com/*/media' => Http::response(['id' => 'media-1']), 'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.S'.++$this->sent]]])]);
    }

    private function inbound(WhatsappConversation $c, string $at): WhatsappMessage
    {
        return WhatsappMessage::create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'body' => 'Hola', 'kind' => 'inbound', 'direction' => 'in', 'message_type' => 'text', 'status' => 'received', 'wa_message_id' => 'wamid.'.bin2hex(random_bytes(6)), 'created_at' => $at]);
    }

    public function test_only_supervision_assigns_an_unknown_number_to_a_customer_of_its_team(): void
    {
        $base = "/api/v1/conversations/{$this->unknown->id}";
        $this->actingAs($this->vera, 'sanctum')->postJson("$base/assign", ['customer_id' => $this->newCustomer->id])->assertNotFound();       // el vendedor ni siquiera la ve
        $this->actingAs($this->admin, 'sanctum')->postJson("$base/assign", ['customer_id' => $this->newCustomer->id])->assertForbidden();
        $this->actingAs($this->supervisor, 'sanctum');

        $ids = array_column($this->getJson("$base/candidates")->assertOk()->json('data'), 'code');
        $this->assertSame(['CN', 'CV'], $ids);                                                                              // solo su equipo (sin el cliente de Beto)
        $this->assertSame(['CN'], array_column($this->getJson("$base/candidates?q=nuevo")->json('data'), 'code'));

        $this->postJson("$base/assign", ['customer_id' => $this->sBeto->id])->assertUnprocessable();                         // id que no es de su equipo
        $betoCustomer = Customer::where('code', 'CB')->value('id');
        $this->postJson("$base/assign", ['customer_id' => $betoCustomer])->assertUnprocessable();
        $r = $this->postJson("$base/assign", ['customer_id' => $this->newCustomer->id])->assertOk();

        $this->assertSame(['Cliente Nuevo', true], [$r->json('data.customer.name'), $r->json('data.can_reply')]);
        $this->assertSame('+50370009999', $this->newCustomer->fresh()->whatsapp);                                           // el cliente no tenía número: queda el de la conversación
        $this->postJson("$base/assign", ['customer_id' => $this->newCustomer->id])->assertUnprocessable();                   // ya está asignada
        $this->assertDatabaseHas('audit_logs', ['event' => 'conversations.assigned']);
        $this->assertContains('50370009999', array_column($this->actingAs($this->vera, 'sanctum')->getJson('/api/v1/conversations')->json('data'), 'wa_number'));   // ahora la ve su vendedor
    }

    public function test_the_conversation_follows_the_customer_when_its_seller_changes(): void
    {
        $this->assertNotContains('50370000001', array_column($this->actingAs($this->beto, 'sanctum')->getJson('/api/v1/conversations')->json('data'), 'wa_number'));
        $this->cv->forceFill(['seller_id' => $this->sBeto->id])->save();

        $this->assertContains('50370000001', array_column($this->actingAs($this->beto, 'sanctum')->getJson('/api/v1/conversations')->json('data'), 'wa_number'));
        $this->actingAs($this->vera, 'sanctum')->getJson("/api/v1/conversations/{$this->conv->id}/messages")->assertNotFound();
        $this->actingAs($this->supervisor, 'sanctum')->getJson("/api/v1/conversations/{$this->conv->id}/messages")->assertNotFound();      // Beto no es de su equipo
    }

    public function test_labels_come_from_a_fixed_list_and_are_audited(): void
    {
        $path = "/api/v1/conversations/{$this->conv->id}/labels";
        $this->actingAs($this->vera, 'sanctum')->putJson($path, ['labels' => ['Reclamo', 'Urgente']])->assertOk()->assertJsonPath('data.labels', ['Reclamo', 'Urgente']);
        $this->putJson($path, ['labels' => ['Inventada']])->assertUnprocessable();
        $this->assertSame(['Reclamo', 'Urgente'], $this->getJson('/api/v1/conversations')->json('data.0.labels'));
        $this->putJson($path, ['labels' => ['Urgente']])->assertOk();
        $this->assertSame(['Urgente'], DB::table('conversation_labels')->pluck('label')->all());
        $this->assertSame(2, DB::table('audit_logs')->where('event', 'conversations.labels_changed')->count());
        $this->actingAs($this->manager, 'sanctum')->putJson($path, ['labels' => []])->assertForbidden();                    // solo lectura
        $this->actingAs($this->beto, 'sanctum')->putJson($path, ['labels' => []])->assertNotFound();
    }

    public function test_quick_replies_are_personal_unless_supervision_shares_them(): void
    {
        $this->actingAs($this->vera, 'sanctum')->postJson('/api/v1/conversations/quick-replies', ['title' => 'Saludo', 'body' => 'Hola {nombre}, ¿en qué te ayudo?'])->assertCreated();
        $this->postJson('/api/v1/conversations/quick-replies', ['title' => 'Para todos', 'body' => 'x', 'shared' => true])->assertForbidden();
        $this->actingAs($this->supervisor, 'sanctum')->postJson('/api/v1/conversations/quick-replies', ['title' => 'Horario', 'body' => 'Atendemos de 8 a 6', 'shared' => true])->assertCreated();

        $titles = fn (User $u) => array_column($this->actingAs($u, 'sanctum')->getJson('/api/v1/conversations/quick-replies')->json('data'), 'title');
        $this->assertSame(['Horario', 'Saludo'], $titles($this->vera));
        $this->assertSame(['Horario'], $titles($this->beto));                                                               // lo personal de Vera no se ve
        $this->postJson('/api/v1/conversations/quick-replies', ['title' => 'x', 'body' => ''])->assertUnprocessable();

        $id = DB::table('quick_replies')->where('title', 'Horario')->value('id');
        $this->actingAs($this->beto, 'sanctum')->putJson("/api/v1/conversations/quick-replies/$id", ['is_active' => false])->assertNotFound();      // solo su dueño
        $this->actingAs($this->supervisor, 'sanctum')->putJson("/api/v1/conversations/quick-replies/$id", ['is_active' => false])->assertOk();
        $this->assertSame([], $titles($this->beto));
        $this->assertDatabaseHas('quick_replies', ['id' => $id, 'is_active' => false]);                                      // no se borra
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/conversations/quick-replies', ['title' => 'Gerente', 'body' => 'x'])->assertForbidden();
    }

    public function test_a_quotation_file_of_the_customer_is_sent_as_a_document(): void
    {
        $this->metaOk();
        Storage::disk('quotations')->put('1/cot.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $q = Quotation::create(['company_id' => $this->conv->company_id, 'customer_id' => $this->cv->id, 'user_id' => $this->vera->id, 'seller_id' => $this->cv->seller_id, 'sequence' => 1, 'number' => 'COT-000001', 'status' => 'sent', 'amount' => 500,
            'attachment_path' => '1/cot.pdf', 'attachment_name' => 'Cotización 1.pdf', 'attachment_mime' => 'application/pdf', 'attachment_size' => 60]);
        $other = Quotation::create(['company_id' => $this->conv->company_id, 'customer_id' => $this->newCustomer->id, 'user_id' => $this->vera->id, 'seller_id' => $this->cv->seller_id, 'sequence' => 2, 'number' => 'COT-000002', 'status' => 'sent', 'amount' => 1,
            'attachment_path' => '1/cot.pdf', 'attachment_name' => 'Otra.pdf', 'attachment_mime' => 'application/pdf', 'attachment_size' => 60]);
        $base = "/api/v1/conversations/{$this->conv->id}";

        $this->actingAs($this->vera, 'sanctum')->getJson("$base/quotations")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.number', 'COT-000001');
        $r = $this->postJson("$base/quotation", ['quotation_id' => $q->id, 'body' => 'Te comparto la cotización'])->assertCreated();

        $this->assertSame(['document', 'sent'], [$r->json('data.type'), $r->json('data.status')]);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/messages') && $req['document']['filename'] === 'Cotización 1.pdf' && $req['document']['caption'] === 'Te comparto la cotización');
        $this->assertSame('application/pdf', WhatsappAttachment::query()->value('mime'));
        $this->postJson("$base/quotation", ['quotation_id' => $other->id])->assertNotFound();                                // cotización de otro cliente
        $this->assertDatabaseHas('audit_logs', ['event' => 'conversations.quotation_sent']);
        $this->actingAs($this->manager, 'sanctum')->postJson("$base/quotation", ['quotation_id' => $q->id])->assertForbidden();
    }

    public function test_a_reply_is_recorded_in_follow_up_once_per_customer_and_day(): void
    {
        $this->metaOk();
        $this->actingAs($this->vera, 'sanctum');
        $this->postJson("/api/v1/conversations/{$this->conv->id}/messages", ['body' => 'Primero'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$this->conv->id}/messages", ['body' => 'Segundo'])->assertCreated();

        $log = CustomerInteraction::query()->where('customer_id', $this->cv->id)->get();
        $this->assertCount(1, $log);
        $this->assertSame(['whatsapp', 'outbound', 'spoke', $this->vera->id, $this->conv->id], [$log[0]->channel, $log[0]->direction, $log[0]->outcome, $log[0]->user_id, $log[0]->conversation_id]);

        // Si el vendedor ya registró a mano un contacto por WhatsApp ese día, no se agrega otro.
        CustomerInteraction::create(['company_id' => $this->conv->company_id, 'customer_id' => $this->newCustomer->id, 'user_id' => $this->vera->id, 'channel' => 'whatsapp', 'direction' => 'outbound', 'outcome' => 'spoke', 'occurred_at' => now()->subHour()]);
        $this->newCustomer->forceFill(['whatsapp' => '+503 7000-0004'])->save();
        $c2 = WhatsappConversation::create(['company_id' => $this->conv->company_id, 'customer_id' => $this->newCustomer->id, 'wa_number' => '50370000004', 'created_at' => now(), 'last_inbound_at' => now()]);
        $this->postJson("/api/v1/conversations/{$c2->id}/messages", ['body' => 'Hola'])->assertCreated();
        $this->assertSame(1, CustomerInteraction::query()->where('customer_id', $this->newCustomer->id)->count());
    }

    public function test_the_away_message_is_sent_once_outside_business_hours_only(): void
    {
        $this->metaOk();
        $away = app(ConversationAway::class);
        WhatsappSetting::current()->forceFill(['away_enabled' => true, 'away_message' => 'Estamos fuera de horario; te escribimos mañana.', 'away_start_hour' => 8, 'away_end_hour' => 18, 'away_days' => '1,2,3,4,5,6'])->save();

        $this->assertFalse($away->handle($this->conv, CarbonImmutable::parse('2026-10-21 10:00:00')));                         // miércoles en horario
        $this->assertTrue($away->handle($this->conv, CarbonImmutable::parse('2026-10-21 07:59:00')));                          // antes de abrir
        $this->assertFalse($away->handle($this->conv, CarbonImmutable::parse('2026-10-21 19:00:00')));                         // ya se avisó hace menos de 12 horas
        $this->assertTrue($away->handle($this->conv, CarbonImmutable::parse('2026-10-22 18:00:00')));                          // al día siguiente, después del cierre
        $this->assertTrue($away->closedAt(WhatsappSetting::current(), CarbonImmutable::parse('2026-10-25 12:00:00')));         // domingo

        $messages = WhatsappMessage::query()->where('kind', 'away')->get();
        $this->assertCount(2, $messages);
        $this->assertSame([null, 'out', 'sent', 'Estamos fuera de horario; te escribimos mañana.'], [$messages[0]->user_id, $messages[0]->direction, $messages[0]->status, $messages[0]->body]);
        $this->assertSame(0, CustomerInteraction::query()->count());                                                           // el mensaje automático no cuenta como contacto

        $sunday = CarbonImmutable::parse('2026-10-25 12:00:00');
        $this->conv->forceFill(['last_away_at' => null])->save();
        $this->cv->forceFill(['do_not_contact' => true])->save();
        $this->assertFalse($away->handle($this->conv, $sunday));                                                               // «no contactar»
        $this->conv->forceFill(['last_inbound_at' => now()->subHours(30)])->save();
        $this->cv->forceFill(['do_not_contact' => false])->save();
        $this->assertFalse($away->handle($this->conv, $sunday));                                                               // ventana de 24 horas cerrada
        WhatsappSetting::current()->forceFill(['away_enabled' => false])->save();
        $this->assertFalse($away->handle($this->betoConv, $sunday));                                                           // desactivado
    }

    public function test_away_settings_are_saved_validated_and_audited(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $base = ['enabled' => true, 'phone_number_id' => '111222', 'api_version' => 'v21.0'];
        $this->putJson('/api/v1/whatsapp/settings', $base + ['away_enabled' => true, 'away_message' => ' '])->assertUnprocessable()->assertJsonValidationErrors('away_message');
        $this->putJson('/api/v1/whatsapp/settings', $base + ['away_enabled' => true, 'away_message' => 'Fuera de horario', 'away_start_hour' => 18, 'away_end_hour' => 8])->assertUnprocessable();
        $r = $this->putJson('/api/v1/whatsapp/settings', $base + ['away_enabled' => true, 'away_message' => 'Fuera de horario', 'away_start_hour' => 9, 'away_end_hour' => 17, 'away_days' => [1, 2, 3, 4, 5]])->assertOk();
        $this->assertSame([true, 9, 17, [1, 2, 3, 4, 5]], [$r->json('data.away_enabled'), $r->json('data.away_start_hour'), $r->json('data.away_end_hour'), $r->json('data.away_days')]);
        $this->putJson('/api/v1/whatsapp/settings', $base)->assertOk()->assertJsonPath('data.away_message', 'Fuera de horario');   // sin enviar el bloque, se conserva
        $this->assertDatabaseHas('audit_logs', ['event' => 'whatsapp.settings_updated']);
    }

    public function test_the_dashboard_shows_waiting_customers_and_response_speed_within_scope(): void
    {
        $this->conv->forceFill(['last_message_at' => now()->subMinutes(40), 'last_inbound_at' => now()->subMinutes(40)])->save();
        $this->inbound($this->conv, now()->subMinutes(100)->toDateTimeString());
        WhatsappMessage::create(['conversation_id' => $this->conv->id, 'customer_id' => $this->cv->id, 'user_id' => $this->vera->id, 'body' => 'Respuesta', 'kind' => 'conversation', 'direction' => 'out', 'message_type' => 'text', 'status' => 'sent', 'created_at' => now()->subMinutes(90)]);   // respondió en 10 min
        $this->inbound($this->conv, now()->subMinutes(40)->toDateTimeString());                                               // sigue esperando desde hace 40
        $this->betoConv->forceFill(['last_message_at' => now()->subHours(2), 'last_inbound_at' => now()->subHours(2)])->save();

        $d = fn (User $u) => $this->actingAs($u, 'sanctum')->getJson('/api/v1/dashboard/conversations?period=month')->assertOk()->json('data');
        $mine = $d($this->vera);
        $this->assertSame([1, 1, 15], [$mine['waiting'], $mine['over_limit'], $mine['limit_minutes']]);
        $this->assertSame(['Cliente Vera', 40], [$mine['top'][0]['customer'], $mine['top'][0]['minutes']]);
        $this->assertSame([2, 1, 1, 10, 100], [$mine['received'], $mine['sent'], $mine['answered'], $mine['median_minutes'], $mine['within_limit_pct']]);
        $this->assertSame(1, $d($this->beto)['waiting']);                                                                    // lo de Beto es de Beto
        $this->assertSame(2, $d($this->manager)['waiting']);                                                                 // gerencia lo ve todo
        $this->assertSame(1, $d($this->supervisor)['unassigned']);                                                           // y supervisión, el buzón sin asignar
    }

    public function test_the_file_check_reports_missing_and_altered_files(): void
    {
        Storage::disk('conversations')->put('1/ok.png', 'contenido');
        $m = WhatsappMessage::create(['conversation_id' => $this->conv->id, 'customer_id' => $this->cv->id, 'body' => '', 'kind' => 'inbound', 'direction' => 'in', 'message_type' => 'image', 'status' => 'received', 'created_at' => now()]);
        DB::table('whatsapp_attachments')->insert([
            ['message_id' => $m->id, 'kind' => 'image', 'path' => '1/ok.png', 'mime' => 'image/png', 'size' => 9, 'sha256' => hash('sha256', 'contenido'), 'status' => 'stored', 'created_at' => now()],
            ['message_id' => $m->id, 'kind' => 'image', 'path' => '1/falta.png', 'mime' => 'image/png', 'size' => 9, 'sha256' => null, 'status' => 'stored', 'created_at' => now()],
        ]);
        $this->artisan('conversations:verify-files')->expectsOutputToContain('faltantes: 1')->assertFailed();
        DB::table('whatsapp_attachments')->where('path', '1/falta.png')->update(['path' => '1/ok.png', 'sha256' => hash('sha256', 'otro')]);
        $this->artisan('conversations:verify-files')->assertSuccessful();
        $this->artisan('conversations:verify-files --checksums')->expectsOutputToContain('alterados: 1')->assertFailed();
    }
}
