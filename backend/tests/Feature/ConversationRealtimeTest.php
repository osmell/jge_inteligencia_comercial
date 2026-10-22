<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\ConversationBroadcast;
use App\Events\NoticeCreated;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\ConversationAlerts;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class ConversationRealtimeTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private User $vera;

    private User $beto;

    private Customer $cv;

    private WhatsappConversation $conv;

    private WhatsappConversation $unknown;

    private WhatsappConversation $betoConv;

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

    private function inbound(WhatsappConversation $c, string $body = 'Hola', ?string $at = null): WhatsappMessage
    {
        return WhatsappMessage::create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'body' => $body, 'kind' => 'inbound', 'direction' => 'in', 'message_type' => 'text', 'status' => 'received', 'wa_message_id' => 'wamid.'.bin2hex(random_bytes(6)), 'created_at' => $at ?? now()]);
    }

    public function test_new_messages_and_status_changes_are_broadcast_only_to_who_can_see_the_conversation(): void
    {
        Event::fake([ConversationBroadcast::class]);
        $m = $this->inbound($this->conv);
        Event::assertDispatched(ConversationBroadcast::class, fn ($e) => $e->kind === 'created' && $e->messageId === $m->id);

        $m->forceFill(['status' => 'read'])->save();
        Event::assertDispatched(ConversationBroadcast::class, fn ($e) => $e->kind === 'updated' && $e->messageId === $m->id);
        $m->forceFill(['body' => 'cambio sin importancia'])->save();
        Event::assertDispatchedTimes(ConversationBroadcast::class, 2);

        $event = new ConversationBroadcast($m->id, 'created');
        $channels = array_map(fn ($c) => $c->name, $event->broadcastOn());
        $this->assertEqualsCanonicalizing(array_map(fn (User $u) => "private-users.{$u->id}.conversations", [$this->vera, $this->supervisor, $this->admin, $this->manager]), $channels);
        $this->assertNotContains("private-users.{$this->beto->id}.conversations", $channels);                              // vendedor de otro cliente
        $payload = $event->broadcastWith();
        $this->assertSame(['cambio sin importancia', 'Cliente Vera', $this->conv->id], [$payload['message']['body'], $payload['conversation']['customer']['name'], $payload['conversation']['id']]);
        $this->assertSame('message.created', $event->broadcastAs());
    }

    public function test_the_unassigned_inbox_is_only_broadcast_to_supervision_and_administration(): void
    {
        $m = $this->inbound($this->unknown);
        $channels = array_map(fn ($c) => $c->name, (new ConversationBroadcast($m->id, 'created'))->broadcastOn());
        $this->assertEqualsCanonicalizing(array_map(fn (User $u) => "private-users.{$u->id}.conversations", [$this->supervisor, $this->admin, $this->manager]), $channels);
    }

    public function test_channels_are_private_to_each_person(): void
    {
        config(['broadcasting.default' => 'reverb', 'broadcasting.connections.reverb.key' => 'k', 'broadcasting.connections.reverb.secret' => 's', 'broadcasting.connections.reverb.app_id' => 'a',
            'broadcasting.connections.reverb.options' => ['host' => 'reverb', 'port' => 8080, 'scheme' => 'http', 'useTLS' => false]]);
        Broadcast::purge();
        require base_path('routes/channels.php');                                                                         // el nuevo emisor empieza sin canales registrados
        $auth = fn (User $as, int $of, string $kind = 'conversations') => $this->actingAs($as, 'sanctum')->postJson('/api/v1/broadcasting/auth', ['socket_id' => '123.456', 'channel_name' => "private-users.$of.$kind"]);

        $auth($this->vera, $this->vera->id)->assertOk()->assertJsonStructure(['auth']);
        $auth($this->vera, $this->beto->id)->assertForbidden();                                                          // el canal de otra persona
        $auth($this->vera, $this->beto->id, 'notifications')->assertForbidden();
        $auth($this->vera, $this->vera->id, 'notifications')->assertOk();
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->vera->role_id, ['permissions' => []])->assertOk();
        $auth(User::find($this->vera->id), $this->vera->id)->assertForbidden();                                                          // sin permiso de ver conversaciones
        Auth::forgetGuards();
        $this->postJson('/api/v1/broadcasting/auth', ['socket_id' => '1.2', 'channel_name' => 'private-users.1.conversations'])->assertUnauthorized();
    }

    public function test_realtime_connection_data_is_only_given_when_configured(): void
    {
        $this->actingAs($this->vera, 'sanctum')->getJson('/api/v1/realtime/config')->assertOk()->assertJsonPath('data', null);
        config(['broadcasting.default' => 'reverb', 'conversations.realtime.key' => 'clave-publica']);
        $r = $this->getJson('/api/v1/realtime/config')->assertOk();
        $this->assertSame('clave-publica', $r->json('data.key'));
        $this->assertArrayNotHasKey('secret', $r->json('data'));
    }

    public function test_a_new_customer_message_rings_the_seller_once_until_the_notice_is_seen(): void
    {
        Event::fake([NoticeCreated::class, ConversationBroadcast::class]);
        $alerts = app(ConversationAlerts::class);
        $alerts->inbound($this->inbound($this->conv, 'Primera línea'));
        $alerts->inbound($this->inbound($this->conv, 'Segunda línea'));

        $notices = UserNotification::query()->where('type', 'conversation_message')->get();
        $this->assertCount(1, $notices);
        $this->assertSame([$this->vera->id, 'Mensaje nuevo de Cliente Vera', '/conversaciones?c='.$this->conv->id], [$notices[0]->user_id, $notices[0]->title, $notices[0]->url]);
        Event::assertDispatched(NoticeCreated::class, fn ($e) => $e->userId === $this->vera->id);

        $notices[0]->forceFill(['status' => 'seen'])->save();
        $alerts->inbound($this->inbound($this->conv, 'Tercera'));
        $this->assertSame(2, UserNotification::query()->where('type', 'conversation_message')->count());
        $this->assertSame(0, UserNotification::query()->where('user_id', $this->beto->id)->count());                      // nadie más
    }

    public function test_unknown_numbers_alert_the_supervisors_of_the_company(): void
    {
        app(ConversationAlerts::class)->inbound($this->inbound($this->unknown));
        $this->assertSame([$this->supervisor->id], UserNotification::query()->where('type', 'conversation_message')->pluck('user_id')->all());
        $this->assertSame('Mensaje nuevo de +50370009999', UserNotification::query()->value('title'));
    }

    private function waiting(WhatsappConversation $c, int $minutes): WhatsappMessage
    {
        $at = now()->subMinutes($minutes);
        $m = $this->inbound($c, 'Necesito ayuda', $at->toDateTimeString());
        $c->forceFill(['last_message_at' => $at, 'last_inbound_at' => $at])->save();

        return $m;
    }

    public function test_a_customer_waiting_too_long_alerts_the_supervisor_once_and_only_while_unanswered(): void
    {
        $alerts = app(ConversationAlerts::class);
        $this->waiting($this->conv, 10);
        $this->assertSame(0, $alerts->waiting());                                                                         // aún dentro del plazo

        $this->waiting($this->conv, 16);
        $this->assertSame(1, $alerts->waiting());
        $this->assertSame(0, $alerts->waiting());                                                                         // no se repite
        $notice = UserNotification::query()->where('type', 'conversation_waiting')->firstOrFail();
        $this->assertSame([$this->supervisor->id, 'Cliente Vera espera respuesta', 'warning'], [$notice->user_id, $notice->title, $notice->severity]);

        $this->waiting($this->conv, 30);                                                                                  // otro mensaje sin atender: otro aviso
        $this->assertSame(1, $alerts->waiting());
        WhatsappMessage::create(['conversation_id' => $this->conv->id, 'customer_id' => $this->cv->id, 'body' => 'Ya te ayudo', 'kind' => 'conversation', 'direction' => 'out', 'message_type' => 'text', 'status' => 'sent', 'created_at' => now()]);
        $this->conv->forceFill(['last_message_at' => now()])->save();
        $this->assertSame(0, $alerts->waiting());                                                                         // ya le contestaron

        $this->waiting($this->betoConv, 40);                                                                              // su vendedor no tiene supervisor
        $this->waiting($this->unknown, 40);                                                                               // sin cliente: no hay responsable
        $this->assertSame(0, $alerts->waiting());
        $this->assertSame(2, UserNotification::query()->where('type', 'conversation_waiting')->count());
    }

    public function test_the_waiting_alert_only_runs_inside_the_notice_window(): void
    {
        $this->waiting($this->conv, 20);
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-25 10:00:00'));                                       // domingo
        $this->artisan('conversations:alert-waiting')->assertSuccessful();
        $this->assertSame(0, UserNotification::query()->count());

        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-26 22:00:00'));                                       // lunes de noche
        $this->artisan('conversations:alert-waiting')->assertSuccessful();
        $this->assertSame(0, UserNotification::query()->count());
        CarbonImmutable::setTestNow();

        $this->artisan('conversations:alert-waiting')->assertSuccessful();                                                // hora real: depende del reloj, solo comprueba que no falla
        $this->assertTrue(true);
    }
}
