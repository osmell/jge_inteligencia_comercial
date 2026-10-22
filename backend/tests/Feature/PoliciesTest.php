<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Operator;
use App\Models\QuickReply;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Models\WhatsappConversation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * Especificación viva de las reglas de acceso a UN registro (docs/25). Cada fila es una regla: rol × situación → permitido/denegado.
 */
class PoliciesTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $supervisor;

    private User $vera;

    private User $beto;

    private Company $alfa;

    private Company $beta;

    private WhatsappConversation $veraConv;

    private WhatsappConversation $betoConv;

    private WhatsappConversation $unknown;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $this->vera = User::factory()->role(Role::VENDEDOR)->create();
        $this->beto = User::factory()->role(Role::VENDEDOR)->create();
        $this->alfa = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        $this->beta = Company::factory()->create(['code' => 'B', 'name' => 'Beta']);
        foreach ([$this->manager, $this->supervisor, $this->vera, $this->beto] as $u) {
            $u->companies()->attach($this->alfa->id);
        }
        $sVera = Seller::create(['company_id' => $this->alfa->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vera->id, 'supervisor_id' => $this->supervisor->id]);
        $sBeto = Seller::create(['company_id' => $this->alfa->id, 'name' => 'Beto', 'normalized_name' => 'beto', 'user_id' => $this->beto->id]);
        $cv = Customer::create(['company_id' => $this->alfa->id, 'code' => 'CV', 'name' => 'Cliente Vera', 'source' => 'sales', 'seller_id' => $sVera->id]);
        $cb = Customer::create(['company_id' => $this->alfa->id, 'code' => 'CB', 'name' => 'Cliente Beto', 'source' => 'sales', 'seller_id' => $sBeto->id]);
        $this->veraConv = WhatsappConversation::create(['company_id' => $this->alfa->id, 'customer_id' => $cv->id, 'wa_number' => '50370000001', 'created_at' => now()]);
        $this->betoConv = WhatsappConversation::create(['company_id' => $this->alfa->id, 'customer_id' => $cb->id, 'wa_number' => '50370000002', 'created_at' => now()]);
        $this->unknown = WhatsappConversation::create(['company_id' => $this->alfa->id, 'customer_id' => null, 'wa_number' => '50370009999', 'created_at' => now()]);
    }

    /** @param  list<string>  $permissions */
    private function grant(string $slug, array $permissions): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', $slug)->value('id'), ['permissions' => $permissions])->assertOk();
        Auth::forgetGuards();
    }

    private function fresh(User $user): User
    {
        return User::query()->with('role.permissions')->findOrFail($user->id);
    }

    public function test_conversations_are_written_only_by_sellers_and_supervisors_within_their_scope(): void
    {
        $this->grant(Role::GERENTE_GENERAL, ['conversations.view', 'conversations.reply']);
        $this->grant(Role::SUPERVISOR_VENTAS, ['conversations.view', 'conversations.reply']);
        $this->grant(Role::VENDEDOR, ['conversations.view', 'conversations.reply']);
        [$admin, $manager, $supervisor, $vera, $beto] = array_map($this->fresh(...), [$this->admin, $this->manager, $this->supervisor, $this->vera, $this->beto]);

        // El vendedor escribe solo en las conversaciones de sus clientes
        $this->assertTrue($vera->can('reply', $this->veraConv));
        $this->assertFalse($vera->can('reply', $this->betoConv));
        $this->assertFalse($vera->can('reply', $this->unknown));
        $this->assertTrue($beto->can('reply', $this->betoConv));
        // El supervisor, en las de los vendedores que supervisa; no en las de otros ni en las sin cliente
        $this->assertTrue($supervisor->can('reply', $this->veraConv));
        $this->assertFalse($supervisor->can('reply', $this->betoConv));
        $this->assertFalse($supervisor->can('reply', $this->unknown));
        // Administración y gerencia solo leen, aunque tengan el permiso de responder
        $this->assertFalse($admin->can('reply', $this->veraConv));
        $this->assertFalse($manager->can('reply', $this->veraConv));
        $this->assertTrue($admin->can('view', $this->veraConv));
        $this->assertTrue($manager->can('view', $this->veraConv));
        // Ver no es escribir
        $this->assertTrue($supervisor->can('view', $this->unknown));
        $this->assertFalse($vera->can('view', $this->betoConv));
    }

    public function test_replying_requires_the_reply_permission(): void
    {
        $this->grant(Role::VENDEDOR, ['conversations.view']);

        $this->assertTrue($this->fresh($this->vera)->can('view', $this->veraConv));
        $this->assertFalse($this->fresh($this->vera)->can('reply', $this->veraConv));
    }

    public function test_the_denial_explains_why(): void
    {
        $this->grant(Role::VENDEDOR, ['conversations.view', 'conversations.reply']);
        $this->expectException(AuthorizationException::class);
        $this->expectExceptionMessage('No puedes escribir en esta conversación.');

        Gate::forUser($this->fresh($this->vera))->authorize('reply', $this->betoConv);
    }

    public function test_only_supervisors_assign_unknown_numbers(): void
    {
        $this->grant(Role::SUPERVISOR_VENTAS, ['conversations.view', 'conversations.reply']);
        $this->grant(Role::VENDEDOR, ['conversations.view', 'conversations.reply']);

        $this->assertTrue($this->fresh($this->supervisor)->can('assign', $this->unknown));
        $this->assertFalse($this->fresh($this->vera)->can('assign', $this->unknown));
        $this->assertFalse($this->fresh($this->admin)->can('assign', $this->unknown));

        $this->grant(Role::SUPERVISOR_VENTAS, ['conversations.view']);
        $this->assertFalse($this->fresh($this->supervisor)->can('assign', $this->unknown));
    }

    public function test_only_supervisors_share_quick_replies(): void
    {
        $this->assertTrue($this->fresh($this->supervisor)->can('share', QuickReply::class));
        $this->assertFalse($this->fresh($this->vera)->can('share', QuickReply::class));
        $this->assertFalse($this->fresh($this->admin)->can('share', QuickReply::class));
    }

    public function test_quotation_actions_follow_the_permissions_not_the_author(): void
    {
        $this->grant(Role::VENDEDOR, ['customers.view', 'interactions.manage']);
        $this->grant(Role::SUPERVISOR_VENTAS, ['customers.view']);
        $this->grant(Role::GERENTE_GENERAL, ['customers.view', 'interactions.manage', 'customers.manage']);
        $someoneElses = new Quotation;
        $someoneElses->forceFill(['user_id' => $this->admin->id, 'company_id' => $this->alfa->id]);

        $vera = $this->fresh($this->vera);
        $this->assertTrue($vera->can('view', $someoneElses));
        $this->assertTrue($vera->can('manage', $someoneElses), 'D2: cualquiera con el permiso modifica cualquier cotización que vea');
        $this->assertTrue($vera->can('download', $someoneElses));
        $this->assertFalse($vera->can('unlink', $someoneElses));

        $supervisor = $this->fresh($this->supervisor);
        $this->assertTrue($supervisor->can('view', $someoneElses));
        $this->assertFalse($supervisor->can('manage', $someoneElses));

        $this->assertTrue($this->fresh($this->manager)->can('unlink', $someoneElses));
        $this->assertTrue($this->fresh($this->admin)->can('manage', $someoneElses));
    }

    public function test_an_interaction_is_corrected_by_its_author_or_by_whoever_manages_customers(): void
    {
        $this->grant(Role::VENDEDOR, ['customers.view', 'interactions.manage']);
        $this->grant(Role::GERENTE_GENERAL, ['customers.view', 'customers.manage']);
        $this->grant(Role::SUPERVISOR_VENTAS, ['customers.view', 'interactions.manage']);
        $interaction = new CustomerInteraction;
        $interaction->forceFill(['user_id' => $this->vera->id]);

        $this->assertTrue($this->fresh($this->vera)->can('update', $interaction));
        $this->assertFalse($this->fresh($this->beto)->can('update', $interaction));
        $this->assertFalse($this->fresh($this->supervisor)->can('update', $interaction), 'D4: el supervisor no corrige contactos ajenos');
        $this->assertTrue($this->fresh($this->manager)->can('update', $interaction));
    }

    public function test_customers_are_managed_with_the_manage_permission(): void
    {
        $this->grant(Role::VENDEDOR, ['customers.view']);
        $this->grant(Role::GERENTE_GENERAL, ['customers.view', 'customers.manage']);
        $customer = new Customer;

        $this->assertTrue($this->fresh($this->vera)->can('view', $customer));
        $this->assertFalse($this->fresh($this->vera)->can('manage', $customer));
        $this->assertTrue($this->fresh($this->manager)->can('manage', $customer));
    }

    public function test_sellers_and_operators_outside_the_full_access_companies_do_not_exist_for_the_actor(): void
    {
        $inside = new Seller;
        $inside->forceFill(['company_id' => $this->alfa->id]);
        $outside = new Seller;
        $outside->forceFill(['company_id' => $this->beta->id]);
        $operatorOutside = new Operator;
        $operatorOutside->forceFill(['company_id' => $this->beta->id]);

        $this->assertTrue($this->fresh($this->admin)->can('update', $outside), 'el administrador no tiene restricción');
        $response = Gate::forUser($this->fresh($this->manager))->inspect('update', $outside);
        $this->assertTrue($response->denied());
        $this->assertSame(404, $response->status());
        $this->assertSame(404, Gate::forUser($this->fresh($this->manager))->inspect('update', $operatorOutside)->status());
        $this->assertTrue(Gate::forUser($this->fresh($this->manager))->inspect('update', $inside)->allowed());
    }

    public function test_company_wide_analysis_is_closed_to_sellers(): void
    {
        $this->assertTrue(Gate::forUser($this->fresh($this->supervisor))->allows('view-company-analysis'));
        $this->assertTrue(Gate::forUser($this->fresh($this->manager))->allows('view-company-analysis'));
        $this->assertFalse(Gate::forUser($this->fresh($this->vera))->allows('view-company-analysis'));
    }
}
