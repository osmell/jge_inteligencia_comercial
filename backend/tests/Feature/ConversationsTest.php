<?php

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationsTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private User $vera;

    private User $beto;

    private WhatsappConversation $cVera;

    private WhatsappConversation $cBeto;

    private WhatsappConversation $unknown;

    private WhatsappConversation $other;

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
        $this->a = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        $this->b = Company::factory()->create(['code' => 'B', 'name' => 'Beta']);
        foreach ([$this->manager, $this->supervisor, $this->vera, $this->beto] as $u) {
            $u->companies()->attach($this->a->id);
        }
        $sVera = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vera->id, 'supervisor_id' => $this->supervisor->id]);
        $sBeto = Seller::create(['company_id' => $this->a->id, 'name' => 'Beto', 'normalized_name' => 'beto', 'user_id' => $this->beto->id]);
        $cv = Customer::create(['company_id' => $this->a->id, 'code' => 'CV', 'name' => 'Cliente Vera', 'source' => 'sales', 'seller_id' => $sVera->id, 'whatsapp' => '+503 7000-0001']);
        $cb = Customer::create(['company_id' => $this->a->id, 'code' => 'CB', 'name' => 'Cliente Beto', 'source' => 'sales', 'seller_id' => $sBeto->id, 'whatsapp' => '+503 7000-0002']);
        $ob = Customer::create(['company_id' => $this->b->id, 'code' => 'OB', 'name' => 'Cliente Beta', 'source' => 'sales', 'whatsapp' => '+503 7000-0003']);
        $conv = fn (int $company, ?int $customer, string $number) => WhatsappConversation::create(['company_id' => $company, 'customer_id' => $customer, 'wa_number' => $number, 'created_at' => now(), 'last_message_at' => now()]);
        $this->cVera = $conv($this->a->id, $cv->id, '50370000001');
        $this->cBeto = $conv($this->a->id, $cb->id, '50370000002');
        $this->unknown = $conv($this->a->id, null, '50370009999');
        $this->other = $conv($this->b->id, $ob->id, '50370000003');

        $perms = ['conversations.view'];
        foreach ([Role::GERENTE_GENERAL => $perms, Role::SUPERVISOR_VENTAS => [...$perms, 'conversations.reply'], Role::VENDEDOR => [...$perms, 'conversations.reply']] as $slug => $list) {
            $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', $slug)->value('id'), ['permissions' => $list])->assertOk();
        }
        Auth::forgetGuards();
    }

    private function message(WhatsappConversation $c, string $direction, string $body, ?string $at = null): WhatsappMessage
    {
        return WhatsappMessage::create(['conversation_id' => $c->id, 'customer_id' => $c->customer_id, 'to_number' => $c->wa_number, 'body' => $body, 'direction' => $direction, 'message_type' => 'text', 'status' => $direction === 'in' ? 'received' : 'sent',
            'wa_message_id' => 'wamid.'.bin2hex(random_bytes(6)), 'created_at' => $at ?? now()]);
    }

    private function ids(User $user): array
    {
        return array_column($this->actingAs($user, 'sanctum')->getJson('/api/v1/conversations')->assertOk()->json('data'), 'wa_number');
    }

    public function test_each_role_only_lists_the_conversations_within_its_scope(): void
    {
        $this->assertSame(['50370000001'], $this->ids($this->vera));
        $this->assertSame(['50370000002'], $this->ids($this->beto));
        $this->assertEqualsCanonicalizing(['50370000001', '50370009999'], $this->ids($this->supervisor));          // su equipo y la bandeja sin asignar de su compañía
        $this->assertCount(4, $this->ids($this->admin));
        $this->assertEqualsCanonicalizing(['50370000001', '50370000002', '50370009999'], $this->ids($this->manager));   // solo su compañía
        Auth::forgetGuards();
        $this->getJson('/api/v1/conversations')->assertUnauthorized();
    }

    public function test_only_sellers_and_supervisors_with_the_customer_in_scope_can_write(): void
    {
        $flags = fn (User $u) => collect($this->actingAs($u, 'sanctum')->getJson('/api/v1/conversations')->json('data'))->pluck('can_reply', 'wa_number')->all();

        $this->assertSame(['50370000001' => true], $flags($this->vera));
        $this->assertEquals(['50370000001' => true, '50370009999' => false], $flags($this->supervisor));                // sin cliente no hay a quién escribir
        $this->assertSame([false], array_values(array_unique($flags($this->admin))));                                 // administración nunca escribe
        $this->assertSame([false], array_values(array_unique($flags($this->manager))));

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->manager->role_id, ['permissions' => ['conversations.view', 'conversations.reply']])->assertOk();
        $this->assertSame([false], array_values(array_unique($flags($this->manager))));                               // ni siquiera con el permiso: el rol es de solo lectura
        $this->actingAs($this->manager, 'sanctum');
        $this->postJson("/api/v1/conversations/{$this->cVera->id}/notes", ['body' => 'x'])->assertForbidden();
    }

    public function test_messages_are_paginated_backwards_and_hidden_outside_the_scope(): void
    {
        foreach (range(1, 120) as $i) {
            $this->message($this->cVera, $i % 2 ? 'in' : 'out', "Mensaje $i");
        }
        $this->message($this->cBeto, 'in', 'Secreto de Beto');

        $first = $this->actingAs($this->vera, 'sanctum')->getJson("/api/v1/conversations/{$this->cVera->id}/messages")->assertOk();
        $this->assertSame(['Mensaje 71', 'Mensaje 120'], [$first->json('data.0.body'), $first->json('data.49.body')]);      // los 50 más recientes, en orden
        $this->assertTrue($first->json('meta.has_more'));
        $second = $this->getJson("/api/v1/conversations/{$this->cVera->id}/messages?before=".$first->json('meta.oldest_id'))->assertOk();
        $this->assertSame(['Mensaje 21', 'Mensaje 70'], [$second->json('data.0.body'), $second->json('data.49.body')]);
        $third = $this->getJson("/api/v1/conversations/{$this->cVera->id}/messages?before=".$second->json('meta.oldest_id'))->assertOk();
        $this->assertCount(20, $third->json('data'));
        $this->assertFalse($third->json('meta.has_more'));

        $this->getJson("/api/v1/conversations/{$this->cBeto->id}/messages")->assertNotFound();                           // conversación ajena
        $this->getJson("/api/v1/conversations/{$this->other->id}/messages")->assertNotFound();
    }

    public function test_unread_counts_per_person_and_clears_when_read(): void
    {
        $this->message($this->cVera, 'in', 'Hola');
        $this->message($this->cVera, 'in', 'Una pregunta');
        $this->message($this->cVera, 'out', 'Respuesta');

        $unread = fn (User $u) => collect($this->actingAs($u, 'sanctum')->getJson('/api/v1/conversations')->json('data'))->firstWhere('wa_number', '50370000001')['unread'];
        $this->assertSame(2, $unread($this->vera));
        $this->assertSame(2, $unread($this->supervisor));
        $this->actingAs($this->vera, 'sanctum')->postJson("/api/v1/conversations/{$this->cVera->id}/read")->assertOk();
        $this->assertSame(0, $unread($this->vera));
        $this->assertSame(2, $unread($this->supervisor));                                                                 // cada persona lleva su propia lectura
        $this->message($this->cVera, 'in', 'Otro');
        $this->assertSame(1, $unread($this->vera));
    }

    public function test_opening_someone_elses_conversation_is_audited_once_per_hour_but_the_sellers_own_is_not(): void
    {
        $this->message($this->cVera, 'in', 'Hola');
        $audited = fn () => DB::table('audit_logs')->where('event', 'conversations.viewed')->count();

        $this->actingAs($this->vera, 'sanctum')->getJson("/api/v1/conversations/{$this->cVera->id}/messages")->assertOk();
        $this->assertSame(0, $audited());
        $this->actingAs($this->supervisor, 'sanctum')->getJson("/api/v1/conversations/{$this->cVera->id}/messages")->assertOk();
        $this->getJson("/api/v1/conversations/{$this->cVera->id}/messages")->assertOk();
        $this->assertSame(1, $audited());
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/conversations/{$this->cVera->id}/messages")->assertOk();
        $this->assertSame(2, $audited());
        $this->assertDatabaseHas('audit_logs', ['event' => 'conversations.viewed', 'user_id' => $this->admin->id]);
    }

    public function test_attachments_are_private_served_with_safe_headers_and_only_to_people_with_access(): void
    {
        Storage::fake('conversations');
        $msg = $this->message($this->cVera, 'in', 'Mira esto');
        Storage::disk('conversations')->put('1/2026/10/foto.jpg', 'JPEGDATA');
        Storage::disk('conversations')->put('1/2026/10/malo.html', '<script>alert(1)</script>');
        $image = WhatsappAttachment::create(['message_id' => $msg->id, 'kind' => 'image', 'path' => '1/2026/10/foto.jpg', 'original_name' => 'foto.jpg', 'mime' => 'image/jpeg', 'size' => 8, 'created_at' => now()]);
        $html = WhatsappAttachment::create(['message_id' => $msg->id, 'kind' => 'document', 'path' => '1/2026/10/malo.html', 'original_name' => 'malo.html', 'mime' => 'text/html', 'size' => 25, 'created_at' => now()]);

        $ok = $this->actingAs($this->vera, 'sanctum')->get("/api/v1/conversations/attachments/{$image->id}")->assertOk();
        $this->assertStringContainsString('inline', $ok->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $ok->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('sandbox', $ok->headers->get('Content-Security-Policy'));

        $danger = $this->get("/api/v1/conversations/attachments/{$html->id}")->assertOk();
        $this->assertStringContainsString('attachment', $danger->headers->get('Content-Disposition'));                    // un HTML nunca se muestra en línea

        $this->actingAs($this->beto, 'sanctum')->get("/api/v1/conversations/attachments/{$image->id}")->assertNotFound();    // otro vendedor
        $this->actingAs($this->supervisor, 'sanctum')->get("/api/v1/conversations/attachments/{$image->id}")->assertOk();
        $this->getJson("/api/v1/conversations/{$this->cVera->id}/messages")->assertJsonPath('data.0.attachments.0.name', 'foto.jpg');
    }

    public function test_internal_notes_are_visible_to_the_team_and_never_part_of_the_messages(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/conversations/{$this->cVera->id}/notes", ['body' => 'Ofrécele el descuento del 5 %'])->assertCreated();
        $this->postJson("/api/v1/conversations/{$this->cBeto->id}/notes", ['body' => 'x'])->assertNotFound();
        $this->postJson("/api/v1/conversations/{$this->cVera->id}/notes", ['body' => ''])->assertUnprocessable();

        $this->actingAs($this->vera, 'sanctum')->getJson("/api/v1/conversations/{$this->cVera->id}/notes")->assertJsonPath('data.0.body', 'Ofrécele el descuento del 5 %');
        $this->getJson("/api/v1/conversations/{$this->cVera->id}/messages")->assertJsonCount(0, 'data');
        $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/conversations/{$this->cVera->id}/notes")->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_the_24_hour_window_and_the_search_and_the_permanence_of_records(): void
    {
        $this->cVera->forceFill(['last_inbound_at' => now()->subHours(23)])->save();
        $this->cBeto->forceFill(['last_inbound_at' => now()->subHours(25)])->save();
        $list = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/conversations')->json('data'))->pluck('window_open', 'wa_number');
        $this->assertSame([true, false], [$list['50370000001'], $list['50370000002']]);

        $this->assertSame(['50370000002'], array_column($this->getJson('/api/v1/conversations?q=beto')->json('data'), 'wa_number'));
        $this->assertSame(['50370009999'], array_column($this->getJson('/api/v1/conversations?q=9999')->json('data'), 'wa_number'));
        $this->assertSame(['50370009999'], array_column($this->getJson('/api/v1/conversations?unassigned=1')->json('data'), 'wa_number'));

        $m = $this->message($this->cVera, 'in', 'Hola');
        $this->expectException(\LogicException::class);
        $m->delete();
    }

    public function test_conversations_are_never_deleted(): void
    {
        $this->expectException(\LogicException::class);
        $this->cVera->delete();
    }
}
