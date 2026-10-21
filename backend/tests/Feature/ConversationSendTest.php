<?php

namespace Tests\Feature;

use App\Jobs\SendWhatsappMessage;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConsent;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use App\Services\ConversationSender;
use App\Services\WhatsappService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationSendTest extends TestCase
{
    use RefreshDatabase;

    private int $sent = 0;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private User $vera;

    private User $beto;

    private Customer $cv;

    private WhatsappConversation $conv;

    private WhatsappConversation $betoConv;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        Storage::fake('conversations');
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
        $this->conv = WhatsappConversation::create(['company_id' => $a->id, 'customer_id' => $this->cv->id, 'wa_number' => '50370000001', 'created_at' => now(), 'last_message_at' => now(), 'last_inbound_at' => now()->subHours(2)]);
        $this->betoConv = WhatsappConversation::create(['company_id' => $a->id, 'customer_id' => $cb->id, 'wa_number' => '50370000002', 'created_at' => now(), 'last_inbound_at' => now()]);
        WhatsappSetting::current()->forceFill(['enabled' => true, 'phone_number_id' => '111222', 'access_token' => 'tok-secreto'])->save();

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
        Http::fake(['graph.facebook.com/*/media' => Http::response(['id' => 'media-1']), 'graph.facebook.com/*/messages' => fn () => Http::response(['messages' => [['id' => 'wamid.OUT'.++$this->sent]]])]);
    }

    private function url(?WhatsappConversation $c = null): string
    {
        return '/api/v1/conversations/'.($c ?? $this->conv)->id.'/messages';
    }

    public function test_a_text_message_is_saved_sent_and_shown_in_the_conversation(): void
    {
        $this->metaOk();
        $r = $this->actingAs($this->vera, 'sanctum')->postJson($this->url(), ['body' => '  Hola, ¿en qué te ayudo?  '])->assertCreated();

        $this->assertSame(['sent', 'out', 'text', 'Hola, ¿en qué te ayudo?', $this->vera->name], [$r->json('data.status'), $r->json('data.direction'), $r->json('data.type'), $r->json('data.body'), $r->json('data.author')]);
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/111222/messages') && $req['type'] === 'text' && $req['to'] === '50370000001' && $req['text']['body'] === 'Hola, ¿en qué te ayudo?' && $req->hasHeader('Authorization', 'Bearer tok-secreto'));
        $this->assertSame('wamid.OUT1', WhatsappMessage::query()->latest('id')->value('wa_message_id'));
        $this->assertSame('Hola, ¿en qué te ayudo?', $this->conv->fresh()->last_message_preview);
        $this->assertDatabaseHas('audit_logs', ['event' => 'conversations.message_queued']);
        $this->assertStringNotContainsString('Hola', (string) \DB::table('audit_logs')->where('event', 'conversations.message_queued')->value('new_values'));  // el contenido no va a Auditoría
    }

    public function test_the_same_client_key_never_sends_twice(): void
    {
        $this->metaOk();
        $this->actingAs($this->vera, 'sanctum');
        $first = $this->postJson($this->url(), ['body' => 'Una vez', 'client_key' => 'abc12345-xyz'])->assertCreated();
        $again = $this->postJson($this->url(), ['body' => 'Una vez', 'client_key' => 'abc12345-xyz'])->assertCreated();

        $this->assertSame($first->json('data.id'), $again->json('data.id'));
        $this->assertSame(1, WhatsappMessage::query()->where('conversation_id', $this->conv->id)->count());
        Http::assertSentCount(1);
    }

    public function test_after_24_hours_free_messages_are_refused_and_only_approved_templates_go(): void
    {
        $this->metaOk();
        $this->conv->forceFill(['last_inbound_at' => now()->subHours(25)])->save();
        $this->actingAs($this->vera, 'sanctum')->postJson($this->url(), ['body' => 'Hola'])->assertUnprocessable()->assertJsonValidationErrors('conversation');
        $this->assertSame(0, WhatsappMessage::query()->count());
        Http::assertNothingSent();

        $tpl = WhatsappTemplate::create(['name' => 'seguimiento', 'language' => 'es', 'category' => 'utility', 'body' => 'Hola {{1}}, ¿seguimos con tu pedido?', 'status' => 'approved']);
        $draft = WhatsappTemplate::create(['name' => 'borrador', 'language' => 'es', 'category' => 'utility', 'body' => 'Hola', 'status' => 'pending']);
        $this->getJson('/api/v1/conversations/templates')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'seguimiento');

        $path = "/api/v1/conversations/{$this->conv->id}/template";
        $this->postJson($path, ['template_id' => $tpl->id, 'params' => ['Cliente']])->assertUnprocessable();           // sin consentimiento vigente
        WhatsappConsent::create(['customer_id' => $this->cv->id, 'status' => 'granted', 'source' => 'Formulario', 'recorded_by' => $this->vera->id, 'created_at' => now()]);
        $this->postJson($path, ['template_id' => $draft->id])->assertUnprocessable();                                     // plantilla sin aprobar
        $ok = $this->postJson($path, ['template_id' => $tpl->id, 'params' => ['Cliente']])->assertCreated();
        $this->assertSame(['template', 'sent'], [$ok->json('data.type'), $ok->json('data.status')]);
        $this->assertSame($this->conv->id, WhatsappMessage::query()->where('status', 'sent')->value('conversation_id'));
    }

    public function test_do_not_contact_and_unconfigured_whatsapp_block_free_messages(): void
    {
        $this->metaOk();
        $this->actingAs($this->vera, 'sanctum');
        WhatsappSetting::current()->forceFill(['enabled' => false])->save();
        $this->postJson($this->url(), ['body' => 'Hola'])->assertUnprocessable();
        WhatsappSetting::current()->forceFill(['enabled' => true])->save();
        $this->cv->forceFill(['do_not_contact' => true])->save();
        $this->postJson($this->url(), ['body' => 'Hola'])->assertUnprocessable()->assertJsonPath('errors.conversation.0', 'El cliente está marcado «no contactar».');
        $this->assertSame(0, WhatsappMessage::query()->count());
    }

    public function test_only_who_can_write_may_send(): void
    {
        $this->metaOk();
        $this->actingAs($this->manager, 'sanctum')->postJson($this->url(), ['body' => 'Hola'])->assertForbidden();        // sin permiso de responder
        $this->actingAs($this->admin, 'sanctum')->postJson($this->url(), ['body' => 'Hola'])->assertForbidden();
        $this->actingAs($this->beto, 'sanctum')->postJson($this->url(), ['body' => 'Hola'])->assertNotFound();            // cliente de otro vendedor
        $this->actingAs($this->vera, 'sanctum')->postJson($this->url($this->betoConv), ['body' => 'Hola'])->assertNotFound();
        $this->actingAs($this->supervisor, 'sanctum')->postJson($this->url(), ['body' => 'Interviene el supervisor'])->assertCreated();   // cliente de su equipo
        $this->actingAs($this->supervisor, 'sanctum')->postJson($this->url($this->betoConv), ['body' => 'No es de su equipo'])->assertNotFound();
        $this->assertSame(1, WhatsappMessage::query()->count());
        Auth::forgetGuards();
        $this->postJson($this->url(), ['body' => 'Hola'])->assertUnauthorized();
    }

    public function test_a_conversation_without_customer_cannot_be_written(): void
    {
        $this->conv->forceFill(['customer_id' => null])->save();
        $this->actingAs($this->supervisor, 'sanctum')->postJson($this->url(), ['body' => 'Hola'])->assertForbidden();
    }

    public function test_an_image_with_caption_is_validated_stored_privately_uploaded_and_quotes_a_message(): void
    {
        $this->metaOk();
        $quoted = WhatsappMessage::create(['conversation_id' => $this->conv->id, 'customer_id' => $this->cv->id, 'body' => '¿Tienen precio?', 'direction' => 'in', 'message_type' => 'text', 'status' => 'received', 'wa_message_id' => 'wamid.IN1', 'created_at' => now()]);
        $r = $this->actingAs($this->vera, 'sanctum')->post($this->url(), ['body' => 'Aquí el catálogo', 'reply_to_id' => $quoted->id, 'file' => $this->png('catálogo <1>.png')], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(['image', 'sent'], [$r->json('data.type'), $r->json('data.status')]);
        $this->assertSame(['id' => $quoted->id, 'direction' => 'in', 'body' => '¿Tienen precio?'], $r->json('data.reply_to'));
        $file = WhatsappAttachment::query()->firstOrFail();
        $this->assertSame(['image', 'image/png', 'stored', 'catálogo 1.png'], [$file->kind, $file->mime, $file->status, $file->original_name]);
        Storage::disk('conversations')->assertExists((string) $file->path);
        $this->assertStringNotContainsString('catálogo', (string) $file->path);                                           // nombre generado
        $this->assertSame('media-1', \DB::table('whatsapp_attachments')->value('media_id'));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/111222/media'));
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/messages') && $req['type'] === 'image' && $req['image']['id'] === 'media-1' && $req['image']['caption'] === 'Aquí el catálogo' && $req['context']['message_id'] === 'wamid.IN1');
        $this->getJson("/api/v1/conversations/attachments/{$file->id}")->assertOk();                                      // se puede ver con el alcance de la persona
    }

    public function test_a_pdf_goes_as_document_with_its_name_and_unsafe_or_oversized_files_are_refused(): void
    {
        $this->metaOk();
        $this->actingAs($this->vera, 'sanctum');
        $pdf = UploadedFile::fake()->createWithContent('Cotización 55.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF");
        $this->post($this->url(), ['file' => $pdf], ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('data.type', 'document');
        Http::assertSent(fn ($req) => str_ends_with($req->url(), '/messages') && $req['type'] === 'document' && $req['document']['filename'] === 'Cotización 55.pdf');

        $count = WhatsappMessage::query()->count();
        $exe = UploadedFile::fake()->createWithContent('instalar.pdf', "MZ\x90\x00\x03\x00\x00\x00 programa");           // dice PDF pero no lo es
        $this->post($this->url(), ['file' => $exe], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $js = UploadedFile::fake()->createWithContent('script.js', 'alert(1)');
        $this->post($this->url(), ['file' => $js], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $big = $this->png('grande.png')->size(6000);                                              // imagen > 5 MB
        $this->post($this->url(), ['file' => $big], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame($count, WhatsappMessage::query()->count());
        $this->assertSame(1, WhatsappAttachment::query()->count());
    }

    public function test_an_audio_cannot_carry_text_and_an_empty_message_is_refused(): void
    {
        $this->metaOk();
        $this->actingAs($this->vera, 'sanctum');
        $this->postJson($this->url(), ['body' => '   '])->assertUnprocessable()->assertJsonValidationErrors('body');
        $this->postJson($this->url(), ['body' => str_repeat('a', 4097)])->assertUnprocessable();
        $this->postJson($this->url(), ['body' => 'x', 'reply_to_id' => 999999])->assertUnprocessable()->assertJsonValidationErrors('reply_to_id');
        Http::assertNothingSent();
    }

    public function test_a_refused_message_can_be_resent_once_and_a_sent_one_never_repeats(): void
    {
        Http::fake(['graph.facebook.com/*/messages' => Http::sequence()->push(['error' => ['message' => 'Parámetro inválido']], 400)->push(['messages' => [['id' => 'wamid.OK']]])]);
        $this->actingAs($this->vera, 'sanctum');
        $failed = $this->postJson($this->url(), ['body' => 'Hola'])->assertCreated();
        $this->assertSame('failed', $failed->json('data.status'));
        $this->assertStringContainsString('Parámetro inválido', $failed->json('data.error'));

        $id = $failed->json('data.id');
        $this->postJson("/api/v1/conversations/messages/$id/retry")->assertOk()->assertJsonPath('data.status', 'sent');
        $this->postJson("/api/v1/conversations/messages/$id/retry")->assertUnprocessable();                                // ya salió
        app(ConversationSender::class)->deliver(WhatsappMessage::findOrFail($id));                                          // un reintento tardío de la cola
        Http::assertSentCount(2);
        $this->actingAs($this->beto, 'sanctum')->postJson("/api/v1/conversations/messages/$id/retry")->assertNotFound();
    }

    public function test_network_failures_stay_queued_for_retry_and_give_up_after_the_last_attempt(): void
    {
        Queue::fake();
        Http::fake(['graph.facebook.com/*' => Http::sequence()->push('', 503)->push(['messages' => [['id' => 'wamid.LATE']]])->push(['messages' => [['id' => 'wamid.LATE2']]])]);
        $id = $this->actingAs($this->vera, 'sanctum')->postJson($this->url(), ['body' => 'Hola'])->assertCreated()->assertJsonPath('data.status', 'queued')->json('data.id');
        Queue::assertPushed(SendWhatsappMessage::class, fn ($j) => $j->messageId === $id);

        $job = new SendWhatsappMessage($id);
        try {
            $job->handle(app(ConversationSender::class));
            $this->fail('Debía lanzar para que la cola reintente.');
        } catch (\RuntimeException) {
        }
        $this->assertSame('queued', WhatsappMessage::findOrFail($id)->status);

        $job->handle(app(ConversationSender::class));
        $this->assertSame(['sent', 'wamid.LATE'], [WhatsappMessage::findOrFail($id)->status, WhatsappMessage::findOrFail($id)->wa_message_id]);
        $job->failed(new \RuntimeException('x'));                                                                          // ya enviado: no se marca fallido
        $this->assertSame('sent', WhatsappMessage::findOrFail($id)->status);

        $second = $this->postJson($this->url(), ['body' => 'Otro'])->json('data.id');
        (new SendWhatsappMessage($second))->failed(new \RuntimeException('x'));
        $this->assertSame('failed', WhatsappMessage::findOrFail($second)->status);
    }

    public function test_the_sent_message_status_is_updated_by_meta_receipts(): void
    {
        $this->metaOk();
        $this->actingAs($this->vera, 'sanctum')->postJson($this->url(), ['body' => 'Hola'])->assertCreated();
        $this->assertSame(1, app(WhatsappService::class)->applyStatuses(['entry' => [['changes' => [['value' => ['statuses' => [['id' => 'wamid.OUT1', 'status' => 'read', 'timestamp' => (string) now()->timestamp]]]]]]]]));
        $this->getJson("/api/v1/conversations/{$this->conv->id}/messages")->assertJsonPath('data.0.status', 'read');
    }
}
