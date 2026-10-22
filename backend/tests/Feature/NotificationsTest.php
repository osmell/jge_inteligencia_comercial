<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Notifier;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class NotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $seller;

    private Notifier $notifier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->seller = User::factory()->role(Role::VENDEDOR)->create();
        $this->notifier = app(Notifier::class);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->seller->role_id, ['permissions' => ['interactions.manage', 'customers.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function send(User $user, string $type = 'followup_due', string $key = 'k1', string $severity = 'info'): ?UserNotification
    {
        return $this->notifier->send($user, $type, 'Tienes 3 acciones vencidas', 'Revisa tu agenda.', '/agenda', $key, $severity);
    }

    public function test_a_notice_is_delivered_once_per_fact_and_a_dismissed_one_does_not_come_back(): void
    {
        $first = $this->send($this->seller);
        $again = $this->send($this->seller);

        $this->assertNotNull($first);
        $this->assertSame($first->id, $again->id);
        $this->assertSame(1, UserNotification::count());
        $this->assertSame('new', $first->status);

        $first->forceFill(['status' => 'dismissed', 'dismissed_at' => now()])->save();
        $this->send($this->seller);
        $this->assertSame(['dismissed'], UserNotification::pluck('status')->all());          // el mismo hecho no resucita el aviso

        $this->send($this->seller, key: 'k2');                                                // otro hecho sí es otro aviso
        $this->assertSame(2, UserNotification::count());
    }

    public function test_a_notice_only_reaches_people_who_could_see_what_it_describes(): void
    {
        $this->assertNull($this->send($this->seller, 'credit_bucket'));                      // sin permiso de crédito
        $this->assertNull($this->send($this->seller, 'inventory_low'));
        $this->assertNotNull($this->send($this->seller, 'quotation_expiring'));
        $this->assertNotNull($this->send($this->seller, 'system'));                          // los avisos del sistema no piden permiso
        $this->assertNull($this->send($this->seller, 'tipo_inexistente'));

        $inactive = User::factory()->role(Role::ADMINISTRADOR)->create(['is_active' => false]);
        $this->assertNull($this->send($inactive));
    }

    public function test_preferences_turn_types_off_and_pausing_stops_everything(): void
    {
        $this->actingAs($this->seller, 'sanctum');
        $prefs = $this->getJson('/api/v1/notifications/preferences')->assertOk();
        $this->assertEqualsCanonicalizing(['followup_due', 'quotation_expiring', 'system'], array_column($prefs->json('data.types'), 'type'));   // solo los que le corresponden
        $this->assertSame([true, true, true], array_column($prefs->json('data.types'), 'enabled'));

        $this->putJson('/api/v1/notifications/preferences', ['types' => ['followup_due' => false, 'credit_bucket' => true]])->assertOk();
        $this->assertNull($this->send($this->seller, 'followup_due'));
        $this->assertNotNull($this->send($this->seller, 'quotation_expiring'));
        $this->assertDatabaseMissing('notification_preferences', ['user_id' => $this->seller->id, 'type' => 'credit_bucket']);   // un tipo que no le corresponde no se guarda

        $this->putJson('/api/v1/notifications/preferences', ['paused' => true])->assertOk()->assertJsonPath('data.paused', true);
        $this->assertNull($this->send($this->seller, 'quotation_expiring', 'k9'));
        $this->putJson('/api/v1/notifications/preferences', ['paused' => false, 'types' => ['followup_due' => true]])->assertOk();
        $this->assertNotNull($this->send($this->seller, 'followup_due', 'k10'));
    }

    public function test_people_only_see_and_change_their_own_notices(): void
    {
        $mine = $this->send($this->seller);
        $other = $this->send($this->admin, 'system', 'a1');

        $this->actingAs($this->seller, 'sanctum');
        $this->assertSame([$mine->id], array_column($this->getJson('/api/v1/notifications')->assertOk()->json('data'), 'id'));
        $this->postJson("/api/v1/notifications/{$other->id}/seen")->assertNotFound();
        $this->postJson("/api/v1/notifications/{$other->id}/dismiss")->assertNotFound();
        $this->assertSame('new', $other->refresh()->status);
    }

    public function test_the_bell_summary_counts_new_ones_and_seen_or_dismissed_ones_leave_the_count(): void
    {
        $a = $this->send($this->seller, key: 'a');
        $b = $this->send($this->seller, key: 'b', severity: 'danger');
        $c = $this->send($this->seller, key: 'c');
        $this->actingAs($this->seller, 'sanctum');

        $summary = $this->getJson('/api/v1/notifications/summary')->assertOk();
        $this->assertSame([3, false], [$summary->json('data.unread'), $summary->json('data.paused')]);
        $this->assertSame('danger', collect($summary->json('data.latest'))->firstWhere('id', $b->id)['severity']);

        $this->postJson("/api/v1/notifications/{$a->id}/seen")->assertOk()->assertJsonPath('data.status', 'seen');
        $this->postJson("/api/v1/notifications/{$b->id}/dismiss")->assertOk()->assertJsonPath('data.status', 'dismissed');
        $this->assertSame(1, $this->getJson('/api/v1/notifications/summary')->json('data.unread'));
        $this->assertNotContains($b->id, array_column($this->getJson('/api/v1/notifications/summary')->json('data.latest'), 'id'));       // los descartados no salen en la campana

        $this->postJson('/api/v1/notifications/seen-all')->assertOk()->assertJsonPath('data.marked', 1);
        $this->assertSame(0, $this->getJson('/api/v1/notifications/summary')->json('data.unread'));
        $this->assertSame('seen', $c->refresh()->status);
    }

    public function test_the_list_filters_by_status_and_type(): void
    {
        $this->send($this->seller, 'followup_due', 'a');
        $this->send($this->seller, 'quotation_expiring', 'b');
        $dismissed = $this->send($this->seller, 'system', 'c');
        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/notifications/{$dismissed->id}/dismiss")->assertOk();

        $this->assertCount(2, $this->getJson('/api/v1/notifications')->json('data'));
        $this->assertSame(['system'], array_column($this->getJson('/api/v1/notifications?status=dismissed')->json('data'), 'type'));
        $this->assertSame(['quotation_expiring'], array_column($this->getJson('/api/v1/notifications?type=quotation_expiring')->json('data'), 'type'));
        $this->getJson('/api/v1/notifications?type=inventado')->assertUnprocessable();
    }

    public function test_the_test_notice_reaches_the_bell_and_old_seen_notices_are_pruned(): void
    {
        $r = $this->actingAs($this->seller, 'sanctum')->postJson('/api/v1/notifications/test')->assertCreated();
        $this->assertTrue($r->json('meta.delivered'));
        $this->assertSame(1, $this->getJson('/api/v1/notifications/summary')->json('data.unread'));

        $this->putJson('/api/v1/notifications/preferences', ['types' => ['system' => false]])->assertOk();
        $this->postJson('/api/v1/notifications/test')->assertOk()->assertJsonPath('meta.delivered', false);        // apagado: no se entrega

        $old = $this->send($this->seller, key: 'old');
        $old->forceFill(['status' => 'seen', 'created_at' => now()->subDays(120)])->save();
        $fresh = $this->send($this->seller, key: 'fresh');
        $stale = $this->send($this->seller, key: 'stale');                      // nuevo y antiguo: no se borra mientras no se vea
        $stale->forceFill(['created_at' => now()->subDays(200)])->save();
        $this->assertSame(1, $this->notifier->prune());
        $this->assertNotNull(UserNotification::find($fresh->id));
        $this->assertNotNull(UserNotification::find($stale->id));
    }

    public function test_it_requires_authentication(): void
    {
        Auth::forgetGuards();
        $this->getJson('/api/v1/notifications/summary')->assertUnauthorized();
    }
}
