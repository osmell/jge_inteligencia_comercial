<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FollowUpAgendaTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $branch;

    private User $admin;

    private User $vendedor;

    private User $supervisor;

    private Seller $vera;

    private Seller $otro;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $this->supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        foreach ([$this->vendedor, $this->supervisor] as $u) {
            $u->companies()->attach($this->a->id);
        }
        $this->vera = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vendedor->id, 'supervisor_id' => $this->supervisor->id]);
        $this->otro = Seller::create(['company_id' => $this->a->id, 'name' => 'Otro', 'normalized_name' => 'otro']);

        foreach ([Role::VENDEDOR, Role::SUPERVISOR_VENTAS] as $slug) {
            $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', $slug)->value('id'), ['permissions' => ['customers.view', 'interactions.manage']])->assertOk();
        }
    }

    private function customer(string $code, ?Seller $seller, array $over = []): Customer
    {
        return Customer::create(['company_id' => $this->a->id, 'code' => $code, 'name' => "Cliente $code", 'seller_id' => $seller?->id, ...$over]);
    }

    private function sale(Customer $c, int $daysAgo): void
    {
        SalesDocument::factory()->create(['company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'customer_code' => $c->code, 'issued_on' => now()->subDays($daysAgo)->toDateString()]);
    }

    private function contact(Customer $c, User $by, array $over = []): CustomerInteraction
    {
        return CustomerInteraction::create([
            'company_id' => $this->a->id, 'customer_id' => $c->id, 'user_id' => $by->id, 'channel' => 'call', 'direction' => 'outbound', 'outcome' => 'interested',
            'occurred_at' => now()->subDays(3), ...$over,
        ]);
    }

    /** @return array<string, mixed> */
    private function agenda(User $as, array $params = []): array
    {
        return $this->actingAs($as, 'sanctum')->getJson('/api/v1/customers/agenda?'.http_build_query($params))->assertOk()->json();
    }

    public function test_pending_actions_are_split_into_overdue_and_upcoming_within_the_horizon(): void
    {
        $c = $this->customer('C1', $this->vera);
        $this->contact($c, $this->vendedor, ['next_action' => 'Vencida', 'next_action_at' => now()->subDays(2)]);
        $this->contact($c, $this->vendedor, ['next_action' => 'Mañana', 'next_action_at' => now()->addDay()]);
        $this->contact($c, $this->vendedor, ['next_action' => 'Lejana', 'next_action_at' => now()->addDays(20)]);
        $this->contact($c, $this->vendedor, ['next_action' => 'Cumplida', 'next_action_at' => now()->subDay(), 'next_action_done_at' => now()]);
        $this->contact($c, $this->vendedor);

        $r = $this->agenda($this->vendedor);

        $this->assertSame(['Vencida'], array_column($r['data']['overdue'], 'next_action'));
        $this->assertSame(2, $r['data']['overdue'][0]['days_overdue']);
        $this->assertSame(['Mañana'], array_column($r['data']['upcoming'], 'next_action'));
        $this->assertSame(1, $r['meta']['overdue_total']);

        $far = $this->agenda($this->vendedor, ['horizon_days' => 30]);
        $this->assertSame(['Mañana', 'Lejana'], array_column($far['data']['upcoming'], 'next_action'));
    }

    public function test_scope_seller_sees_own_customers_supervisor_sees_team_and_own_contacts(): void
    {
        $mine = $this->customer('C1', $this->vera);
        $foreign = $this->customer('C2', $this->otro);
        $this->contact($mine, $this->supervisor, ['next_action' => 'De mi cliente', 'next_action_at' => now()->subDay()]);
        $this->contact($foreign, $this->admin, ['next_action' => 'Ajena', 'next_action_at' => now()->subDay()]);

        $this->assertSame(['De mi cliente'], array_column($this->agenda($this->vendedor)['data']['overdue'], 'next_action'));
        $this->assertSame(['De mi cliente'], array_column($this->agenda($this->supervisor)['data']['overdue'], 'next_action'));
        $this->assertSame(['De mi cliente', 'Ajena'], array_column($this->agenda($this->admin)['data']['overdue'], 'next_action'));

        // El supervisor también ve lo que él mismo registró, aunque el cliente no sea de su equipo.
        $this->contact($foreign, $this->supervisor, ['next_action' => 'Mía', 'next_action_at' => now()->subHours(5)]);
        $this->assertContains('Mía', array_column($this->agenda($this->supervisor)['data']['overdue'], 'next_action'));
        $this->assertNotContains('Ajena', array_column($this->agenda($this->supervisor)['data']['overdue'], 'next_action'));

        $this->assertSame(['Mía'], array_column($this->agenda($this->supervisor, ['mine' => 1, 'seller_id' => $this->otro->id])['data']['overdue'], 'next_action'));
        $this->assertSame(['Ajena', 'Mía'], array_column($this->agenda($this->admin, ['seller_id' => $this->otro->id, 'company_id' => $this->a->id])['data']['overdue'], 'next_action'));
    }

    public function test_inactive_and_lost_customers_without_recent_contact_are_listed_to_reactivate(): void
    {
        $inactive = $this->customer('C3', $this->vera);
        $lost = $this->customer('C4', $this->vera);
        $contacted = $this->customer('C5', $this->vera);
        $optOut = $this->customer('C6', $this->vera);
        $optOut->forceFill(['do_not_contact' => true])->save();
        $active = $this->customer('C7', $this->vera);
        $this->sale($inactive, 45);
        $this->sale($lost, 120);
        $this->sale($contacted, 50);
        $this->sale($optOut, 50);
        $this->sale($active, 5);
        $this->contact($contacted, $this->vendedor, ['occurred_at' => now()->subDays(3)]);

        $r = $this->agenda($this->vendedor);

        $this->assertSame(['C3', 'C4'], array_column($r['data']['reactivate'], 'code'));      // inactivos primero, luego perdidos
        $this->assertSame(['inactive', 'lost'], array_column($r['data']['reactivate'], 'status'));
        $this->assertSame(45, $r['data']['reactivate'][0]['days_since_last_purchase']);
        $this->assertSame('Vera', $r['data']['reactivate'][0]['seller']);
        $this->assertNull($r['data']['reactivate'][0]['last_contact_at']);

        // Con una ventana de contacto más corta, vuelve a aparecer el contactado hace 3 días… y con una más larga, se mantiene fuera.
        $this->assertContains('C5', array_column($this->agenda($this->vendedor, ['no_contact_days' => 2])['data']['reactivate'], 'code'));
        $this->assertNotContains('C5', array_column($this->agenda($this->vendedor, ['no_contact_days' => 60])['data']['reactivate'], 'code'));
    }

    public function test_seller_does_not_see_unassigned_customers_to_reactivate(): void
    {
        $this->sale($this->customer('C8', $this->otro), 45);
        $this->sale($this->customer('C9', $this->vera), 45);

        $this->assertSame(['C9'], array_column($this->agenda($this->vendedor)['data']['reactivate'], 'code'));
        $this->assertSame(['C9'], array_column($this->agenda($this->supervisor)['data']['reactivate'], 'code'));
        $this->assertEqualsCanonicalizing(['C8', 'C9'], array_column($this->agenda($this->admin)['data']['reactivate'], 'code'));
    }

    public function test_an_action_is_completed_and_reopened_with_audit(): void
    {
        $c = $this->customer('C1', $this->vera);
        $i = $this->contact($c, $this->vendedor, ['next_action' => 'Llamar', 'next_action_at' => now()->subDay()]);
        $this->actingAs($this->vendedor, 'sanctum');

        $this->putJson("/api/v1/customers/interactions/{$i->id}/complete", ['done' => true])->assertOk()->assertJsonPath('data.next_action_done_at', fn ($v) => $v !== null);
        $this->assertSame([], $this->agenda($this->vendedor)['data']['overdue']);
        $this->putJson("/api/v1/customers/interactions/{$i->id}/complete", ['done' => true])->assertOk();
        $this->assertSame(1, AuditLog::where('event', 'customer_interactions.action_completed')->count());

        $this->putJson("/api/v1/customers/interactions/{$i->id}/complete", ['done' => false])->assertOk()->assertJsonPath('data.next_action_done_at', null);
        $this->assertCount(1, $this->agenda($this->vendedor)['data']['overdue']);
        $this->assertSame(1, AuditLog::where('event', 'customer_interactions.action_reopened')->count());

        $plain = $this->contact($c, $this->vendedor);
        $this->putJson("/api/v1/customers/interactions/{$plain->id}/complete", ['done' => true])->assertUnprocessable();
        $this->putJson("/api/v1/customers/interactions/{$i->id}/complete", [])->assertUnprocessable();
    }

    public function test_a_seller_cannot_complete_an_action_of_a_customer_that_is_not_theirs(): void
    {
        $i = $this->contact($this->customer('C2', $this->otro), $this->admin, ['next_action' => 'Ajena', 'next_action_at' => now()->subDay()]);

        $this->actingAs($this->vendedor, 'sanctum')->putJson("/api/v1/customers/interactions/{$i->id}/complete", ['done' => true])->assertNotFound();
    }

    public function test_changing_the_next_action_reopens_it(): void
    {
        $c = $this->customer('C1', $this->vera);
        $i = $this->contact($c, $this->vendedor, ['next_action' => 'Llamar', 'next_action_at' => now()->subDay(), 'next_action_done_at' => now(), 'next_action_done_by' => $this->vendedor->id]);

        $this->actingAs($this->vendedor, 'sanctum')->putJson("/api/v1/customers/interactions/{$i->id}", [
            'channel' => 'call', 'direction' => 'outbound', 'outcome' => 'interested', 'occurred_at' => now()->subDays(3)->toIso8601String(),
            'next_action' => 'Llamar otra vez', 'next_action_at' => now()->addDay()->toIso8601String(),
        ])->assertOk()->assertJsonPath('data.next_action_done_at', null);
    }

    public function test_the_agenda_needs_the_permission_and_validates_parameters(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->supervisor->role_id, ['permissions' => ['customers.view']])->assertOk();
        $this->actingAs($this->supervisor->fresh(), 'sanctum')->getJson('/api/v1/customers/agenda')->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/customers/agenda?horizon_days=500')->assertUnprocessable()->assertJsonValidationErrors(['horizon_days']);
    }
}
