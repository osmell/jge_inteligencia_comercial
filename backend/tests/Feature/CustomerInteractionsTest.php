<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerInteractionsTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private User $admin;

    private User $vendedor;

    private User $supervisor;

    private Customer $mine;

    private Customer $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $this->supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        foreach ([$this->vendedor, $this->supervisor] as $u) {
            $u->companies()->attach($this->a->id);
        }
        $seller = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vendedor->id]);
        $this->mine = Customer::create(['company_id' => $this->a->id, 'code' => 'C1', 'name' => 'Cliente Uno', 'seller_id' => $seller->id]);
        $this->other = Customer::create(['company_id' => $this->a->id, 'code' => 'C2', 'name' => 'Cliente Dos']);
        unset($branch);

        // Los permisos se otorgan desde «Roles y permisos»: vendedores y supervisores consultan clientes y registran contactos.
        foreach ([Role::VENDEDOR, Role::SUPERVISOR_VENTAS] as $slug) {
            $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', $slug)->value('id'), ['permissions' => ['customers.view', 'interactions.manage']])->assertOk();
        }
        $this->app['auth']->forgetGuards();
    }

    /** @return array<string, mixed> */
    private function payload(array $over = []): array
    {
        return [
            'company_id' => $this->a->id, 'code' => 'C1', 'channel' => 'call', 'direction' => 'outbound', 'outcome' => 'interested',
            'occurred_at' => now()->subHour()->toIso8601String(), 'response' => 'Pidió precios', 'next_action' => 'Enviar lista', 'next_action_at' => now()->addDay()->toIso8601String(),
            ...$over,
        ];
    }

    private function qs(string $code = 'C1'): string
    {
        return '?'.http_build_query(['company_id' => $this->a->id, 'code' => $code]);
    }

    public function test_seller_registers_lists_and_audits_an_interaction(): void
    {
        $id = $this->actingAs($this->vendedor, 'sanctum')->postJson('/api/v1/customers/interactions', $this->payload())
            ->assertCreated()->assertJsonPath('data.user.id', $this->vendedor->id)->assertJsonPath('data.can_edit', true)->json('data.id');

        $this->getJson('/api/v1/customers/interactions'.$this->qs())->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.outcome', 'interested')
            ->assertJsonPath('meta.contact_preference.do_not_contact', false);
        $this->assertSame(1, AuditLog::where('event', 'customer_interactions.created')->count());
    }

    public function test_seller_cannot_reach_a_customer_that_is_not_assigned(): void
    {
        $this->actingAs($this->vendedor, 'sanctum')->postJson('/api/v1/customers/interactions', $this->payload(['code' => 'C2']))->assertNotFound();
        $this->getJson('/api/v1/customers/interactions'.$this->qs('C2'))->assertNotFound();
    }

    public function test_supervisor_can_register_for_any_customer_of_the_company(): void
    {
        $this->actingAs($this->supervisor, 'sanctum')->postJson('/api/v1/customers/interactions', $this->payload(['code' => 'C2']))->assertCreated();
    }

    public function test_a_user_without_the_permission_can_read_but_not_register(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->supervisor->role_id, ['permissions' => ['customers.view']])->assertOk();
        $this->actingAs($this->supervisor->fresh(), 'sanctum');

        $this->getJson('/api/v1/customers/interactions'.$this->qs('C2'))->assertOk();
        $this->postJson('/api/v1/customers/interactions', $this->payload(['code' => 'C2']))->assertForbidden();
        $this->putJson('/api/v1/customers/contact-preference', ['company_id' => $this->a->id, 'code' => 'C2', 'do_not_contact' => true, 'reason' => 'x'])->assertForbidden();
    }

    public function test_validation(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->postJson('/api/v1/customers/interactions', $this->payload(['channel' => 'fax', 'outcome' => 'sold']))->assertUnprocessable()->assertJsonValidationErrors(['channel', 'outcome']);
        $this->postJson('/api/v1/customers/interactions', $this->payload(['occurred_at' => now()->addDay()->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors(['occurred_at']);
        $this->postJson('/api/v1/customers/interactions', $this->payload(['next_action' => null]))->assertUnprocessable()->assertJsonValidationErrors(['next_action']);
        $this->postJson('/api/v1/customers/interactions', $this->payload(['next_action_at' => now()->subDays(2)->toIso8601String()]))->assertUnprocessable()->assertJsonValidationErrors(['next_action_at']);
        $this->postJson('/api/v1/customers/interactions', $this->payload(['next_action' => null, 'next_action_at' => null, 'response' => null]))->assertCreated();
    }

    public function test_only_the_author_or_a_customer_manager_corrects_an_interaction(): void
    {
        $id = $this->actingAs($this->supervisor, 'sanctum')->postJson('/api/v1/customers/interactions', $this->payload())->assertCreated()->json('data.id');
        $edit = ['response' => 'Corregido'] + $this->payload();
        unset($edit['company_id'], $edit['code']);

        // El vendedor ve el contacto pero no es su autor.
        $this->actingAs($this->vendedor, 'sanctum')->putJson("/api/v1/customers/interactions/$id", $edit)->assertForbidden();
        $this->getJson('/api/v1/customers/interactions'.$this->qs())->assertJsonPath('data.0.can_edit', false);

        $this->actingAs($this->supervisor, 'sanctum')->putJson("/api/v1/customers/interactions/$id", $edit)->assertOk()->assertJsonPath('data.response', 'Corregido');
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/customers/interactions/$id", array_merge($edit, ['outcome' => 'no_answer']))->assertOk()->assertJsonPath('data.outcome', 'no_answer');
        $this->assertSame(2, AuditLog::where('event', 'customer_interactions.updated')->count());
        $this->putJson("/api/v1/customers/interactions/$id", $edit + ['code' => 'C9'])->assertUnprocessable();
    }

    public function test_do_not_contact_blocks_outbound_contacts_and_follow_ups(): void
    {
        $this->actingAs($this->vendedor, 'sanctum');
        $this->putJson('/api/v1/customers/contact-preference', ['company_id' => $this->a->id, 'code' => 'C1', 'do_not_contact' => true])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->putJson('/api/v1/customers/contact-preference', ['company_id' => $this->a->id, 'code' => 'C1', 'do_not_contact' => true, 'reason' => 'Lo pidió por correo'])
            ->assertOk()->assertJsonPath('data.do_not_contact', true)->assertJsonPath('data.reason', 'Lo pidió por correo');

        $this->postJson('/api/v1/customers/interactions', $this->payload())->assertUnprocessable()->assertJsonValidationErrors(['direction']);
        $this->postJson('/api/v1/customers/interactions', $this->payload(['direction' => 'inbound']))->assertUnprocessable()->assertJsonValidationErrors(['next_action_at']);
        $this->postJson('/api/v1/customers/interactions', $this->payload(['direction' => 'inbound', 'next_action' => null, 'next_action_at' => null]))->assertCreated();

        $profile = $this->getJson('/api/v1/customers/profile'.$this->qs())->assertOk();
        $profile->assertJsonPath('data.customer.do_not_contact', true);

        $this->putJson('/api/v1/customers/contact-preference', ['company_id' => $this->a->id, 'code' => 'C1', 'do_not_contact' => false])->assertOk()->assertJsonPath('data.do_not_contact', false);
        $this->postJson('/api/v1/customers/interactions', $this->payload())->assertCreated();
        $this->assertSame(2, AuditLog::where('event', 'customers.contact_preference_updated')->count());
    }
}
