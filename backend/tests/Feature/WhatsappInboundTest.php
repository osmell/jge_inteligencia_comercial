<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\DownloadWhatsappMedia;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\WhatsappMedia;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WhatsappInboundTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');
        $this->seed(RolePermissionSeeder::class);
        $admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        $this->b = Company::factory()->create(['code' => 'B', 'name' => 'Beta']);
        $this->customer = Customer::create(['company_id' => $this->a->id, 'code' => 'C1', 'name' => 'Cliente Uno', 'source' => 'sales', 'whatsapp' => '+503 7000-1234']);
        $this->actingAs($admin, 'sanctum')->putJson('/api/v1/whatsapp/settings', ['enabled' => true, 'phone_number_id' => '1234567890', 'api_version' => 'v21.0', 'access_token' => 'tok', 'app_secret' => 'app-secreto', 'verify_token' => 'vt-9x2', 'unassigned_company_id' => $this->b->id])->assertOk();
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @param array<string, mixed> $message */
    private function deliver(array $message, string $name = 'Cliente WA', string $phoneId = '1234567890', string $signedWith = 'app-secreto')
    {
        $payload = (string) json_encode(['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $phoneId], 'contacts' => [['wa_id' => $message['from'], 'profile' => ['name' => $name]]], 'messages' => [$message],
        ]]]]]]);

        return $this->call('POST', '/api/v1/webhooks/whatsapp', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $signedWith)], $payload);
    }

    private function text(string $id, string $body, string $from = '50370001234'): array
    {
        return ['id' => $id, 'from' => $from, 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $body]];
    }

    public function test_a_text_from_a_known_customer_lands_in_their_conversation_and_opens_the_24_hour_window(): void
    {
        $this->deliver($this->text('wamid.1', 'Hola, ¿tienen aceite?'))->assertOk()->assertJsonPath('received', 1);

        $c = WhatsappConversation::sole();
        $this->assertSame([$this->a->id, $this->customer->id, '50370001234', 'Cliente WA', 'Hola, ¿tienen aceite?'], [$c->company_id, $c->customer_id, $c->wa_number, $c->profile_name, $c->last_message_preview]);
        $this->assertTrue($c->windowOpen());
        $m = WhatsappMessage::sole();
        $this->assertSame([$c->id, 'in', 'text', 'received', 'Hola, ¿tienen aceite?'], [$m->conversation_id, $m->direction, $m->message_type, $m->status, $m->body]);
        Carbon::setTestNow(now()->addHours(25));
        $this->assertFalse($c->refresh()->windowOpen());
    }

    public function test_the_same_message_is_stored_once_even_if_meta_repeats_it(): void
    {
        $this->deliver($this->text('wamid.1', 'Hola'))->assertJsonPath('received', 1);
        $this->deliver($this->text('wamid.1', 'Hola'))->assertJsonPath('received', 0);

        $this->assertSame(1, WhatsappMessage::count());
        $this->assertSame(1, WhatsappConversation::count());
    }

    public function test_an_unknown_number_goes_to_the_unassigned_inbox_of_the_chosen_company_and_is_linked_later(): void
    {
        $this->deliver($this->text('wamid.1', 'Buenas', '50377770000'), 'Desconocido')->assertOk();

        $c = WhatsappConversation::sole();
        $this->assertSame([null, $this->b->id, 'Desconocido'], [$c->customer_id, $c->company_id, $c->profile_name]);

        $late = Customer::create(['company_id' => $this->b->id, 'code' => 'N1', 'name' => 'Nuevo', 'source' => 'manual', 'whatsapp' => '+503 7777-0000']);
        $this->deliver($this->text('wamid.2', 'Sigo aquí', '50377770000'))->assertOk();
        $this->assertSame($late->id, $c->refresh()->customer_id);                      // el número ya es de un cliente: la conversación se asigna sola
        $this->assertSame(1, WhatsappConversation::count());
    }

    public function test_a_number_shared_by_two_companies_goes_to_the_one_with_the_most_recent_purchase(): void
    {
        $other = Customer::create(['company_id' => $this->b->id, 'code' => 'B1', 'name' => 'Mismo cliente', 'source' => 'sales', 'whatsapp' => '+503 7000 1234']);
        $branch = Branch::factory()->create(['company_id' => $this->b->id]);
        $operator = Operator::create(['company_id' => $this->b->id, 'name' => 'Caja', 'normalized_name' => 'caja']);
        $seller = Seller::create(['company_id' => $this->b->id, 'name' => 'V', 'normalized_name' => 'v']);
        DB::table('sales_documents')->insert(['company_id' => $this->b->id, 'branch_id' => $branch->id, 'series' => 'F', 'number' => '1', 'issued_on' => '2026-10-15', 'customer_code' => 'B1', 'customer_name' => 'x', 'seller_id' => $seller->id, 'operator_id' => $operator->id,
            'import_batch_id' => ImportBatch::factory()->create(['type' => 'sales', 'user_id' => User::first()->id])->id, 'lines_count' => 1, 'content_hash' => 'h', 'created_at' => now(), 'updated_at' => now()]);

        $this->deliver($this->text('wamid.1', 'Hola'))->assertOk();

        $this->assertSame([$this->b->id, $other->id], [WhatsappConversation::sole()->company_id, WhatsappConversation::sole()->customer_id]);
    }

    public function test_replies_reactions_locations_buttons_and_unsupported_types_are_kept(): void
    {
        $this->deliver($this->text('wamid.1', 'Primero'));
        $first = WhatsappMessage::where('wa_message_id', 'wamid.1')->value('id');
        $this->deliver($this->text('wamid.2', 'Respondo a eso') + ['context' => ['id' => 'wamid.1']]);
        $this->deliver(['id' => 'wamid.3', 'from' => '50370001234', 'timestamp' => (string) now()->timestamp, 'type' => 'reaction', 'reaction' => ['message_id' => 'wamid.1', 'emoji' => '👍']]);
        $this->deliver(['id' => 'wamid.4', 'from' => '50370001234', 'timestamp' => (string) now()->timestamp, 'type' => 'location', 'location' => ['latitude' => 13.69, 'longitude' => -89.19, 'name' => 'Taller']]);
        $this->deliver(['id' => 'wamid.5', 'from' => '50370001234', 'timestamp' => (string) now()->timestamp, 'type' => 'interactive', 'interactive' => ['button_reply' => ['title' => 'Sí, confirmo']]]);
        $this->deliver(['id' => 'wamid.6', 'from' => '50370001234', 'timestamp' => (string) now()->timestamp, 'type' => 'order']);

        $by = fn (string $id) => WhatsappMessage::where('wa_message_id', $id)->first();
        $this->assertSame($first, $by('wamid.2')->reply_to_id);
        $this->assertSame(['reaction', '👍', $first], [$by('wamid.3')->message_type, $by('wamid.3')->body, $by('wamid.3')->reply_to_id]);
        $this->assertSame(['location', 'Taller · 13.69,-89.19'], [$by('wamid.4')->message_type, $by('wamid.4')->body]);
        $this->assertSame('Sí, confirmo', $by('wamid.5')->body);
        $this->assertSame('unsupported', $by('wamid.6')->message_type);                       // no se pierde, se guarda como no compatible
        $this->assertSame(6, WhatsappMessage::count());
    }

    public function test_a_message_from_another_number_or_with_a_bad_signature_is_ignored(): void
    {
        $this->deliver($this->text('wamid.1', 'Hola'), 'X', 'otro-numero')->assertOk()->assertJsonPath('received', 0);
        $this->deliver($this->text('wamid.2', 'Hola'), 'X', '1234567890', 'secreto-falso')->assertForbidden();

        $this->assertSame(0, WhatsappMessage::count());
    }

    public function test_an_incoming_file_is_saved_pending_and_then_downloaded_to_the_private_folder(): void
    {
        Queue::fake();
        $this->deliver(['id' => 'wamid.1', 'from' => '50370001234', 'timestamp' => (string) now()->timestamp, 'type' => 'image', 'image' => ['id' => 'MEDIA1', 'caption' => 'Mira esta pieza']])->assertJsonPath('received', 1);

        $attachment = WhatsappAttachment::sole();
        $this->assertSame(['pending', 'image', 'MEDIA1'], [$attachment->status, $attachment->kind, $attachment->media_id]);
        $this->assertSame('Mira esta pieza', WhatsappMessage::sole()->body);
        Queue::assertPushed(DownloadWhatsappMedia::class, fn ($job) => $job->attachmentId === $attachment->id);
    }

    public function test_the_download_stores_a_verified_file_and_rejects_unsafe_ones(): void
    {
        Storage::fake('conversations');
        Queue::fake();
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $this->deliver(['id' => 'wamid.1', 'from' => '50370001234', 'timestamp' => (string) now()->timestamp, 'type' => 'image', 'image' => ['id' => 'OK1']])->assertOk();
        $this->deliver(['id' => 'wamid.2', 'from' => '50370001234', 'timestamp' => (string) now()->timestamp, 'type' => 'document', 'document' => ['id' => 'BAD1', 'filename' => 'virus.exe']])->assertOk();
        $this->deliver(['id' => 'wamid.3', 'from' => '50370001234', 'timestamp' => (string) now()->timestamp, 'type' => 'image', 'image' => ['id' => 'FAKE1']])->assertOk();

        Http::fake([
            'graph.facebook.com/v21.0/OK1' => Http::response(['url' => 'https://lookaside.fbsbx.com/ok', 'mime_type' => 'image/png', 'sha256' => hash('sha256', $png), 'file_size' => strlen($png)]),
            'lookaside.fbsbx.com/ok' => Http::response($png),
            'graph.facebook.com/v21.0/BAD1' => Http::response(['url' => 'https://lookaside.fbsbx.com/bad', 'mime_type' => 'application/x-msdownload', 'file_size' => 10]),
            'graph.facebook.com/v21.0/FAKE1' => Http::response(['url' => 'https://lookaside.fbsbx.com/fake', 'mime_type' => 'image/png', 'file_size' => 20]),
            'lookaside.fbsbx.com/fake' => Http::response('<?php echo "no soy una imagen"; ?>'),
        ]);
        foreach (WhatsappAttachment::orderBy('id')->get() as $a) {
            app(WhatsappMedia::class)->download($a);
        }

        [$ok, $bad, $fake] = WhatsappAttachment::orderBy('id')->get()->all();
        $this->assertSame(['stored', 'image/png'], [$ok->status, $ok->mime]);
        $this->assertMatchesRegularExpression('#^'.$this->a->id.'/2026/10/[0-9a-f-]{36}\.png$#', (string) $ok->path);       // nombre generado, no el del cliente
        Storage::disk('conversations')->assertExists((string) $ok->path);
        $this->assertSame($png, Storage::disk('conversations')->get((string) $ok->path));
        $this->assertSame('failed', $bad->status);
        $this->assertStringContainsString('no permitido', (string) $bad->error);
        $this->assertSame('failed', $fake->status);
        $this->assertStringContainsString('no corresponde', (string) $fake->error);
        $this->assertSame(3, WhatsappMessage::count());                                                                  // los mensajes se conservan aunque el archivo falle
        $this->assertCount(1, Storage::disk('conversations')->allFiles());                                              // solo el archivo válido llegó al disco
    }

    public function test_a_stored_attachment_cannot_be_changed_or_deleted(): void
    {
        $msg = WhatsappMessage::create(['body' => 'x', 'status' => 'received', 'created_at' => now()]);
        $a = WhatsappAttachment::create(['message_id' => $msg->id, 'kind' => 'image', 'status' => 'stored', 'path' => 'p', 'mime' => 'image/png', 'size' => 1, 'created_at' => now()]);

        $this->expectException(\LogicException::class);
        $a->forceFill(['path' => 'otro'])->save();
    }
}
