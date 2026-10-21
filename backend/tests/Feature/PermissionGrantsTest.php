<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PermissionGrantsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    private function grant(string $role, array $slugs): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', $role)->value('id'), ['permissions' => $slugs])
            ->assertOk();
    }

    /** Usuario con el rol indicado, con acceso completo a las compañías dadas. */
    private function userWith(string $role, array $companies = []): User
    {
        $user = User::factory()->role($role)->create();
        $user->companies()->attach(collect($companies)->pluck('id'));

        return $user;
    }

    public function test_matrix_requires_roles_manage(): void
    {
        $this->actingAs($this->userWith(Role::GERENTE_GENERAL), 'sanctum')->getJson('/api/v1/admin/role-permissions')->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/role-permissions')
            ->assertOk()
            ->assertJsonCount(25, 'data.permissions')
            ->assertJsonCount(4, 'data.roles')
            ->assertJsonPath('data.roles.0.locked', true);
    }

    public function test_each_menu_option_has_its_own_permission(): void
    {
        $this->assertEqualsCanonicalizing(
            ['companies.manage', 'branches.manage', 'users.manage', 'roles.manage', 'audit.view', 'imports.manage', 'sales.view', 'customers.view', 'customers.manage', 'goals.manage', 'interactions.manage', 'credit.view', 'credit.payments', 'credit.promises', 'credit.notes', 'inventory.view', 'inventory.manage', 'inventory.costs', 'insights.view', 'insights.manage', 'digests.view', 'mail.manage', 'whatsapp.manage', 'webhooks.manage', 'automations.manage'],
            Permission::pluck('slug')->all(),
        );
    }

    public function test_granting_a_permission_gives_access_to_that_option_only(): void
    {
        $company = Company::factory()->create();
        $gerente = $this->userWith(Role::GERENTE_GENERAL, [$company]);

        $this->actingAs($gerente, 'sanctum')->getJson('/api/v1/admin/companies')->assertForbidden();

        $this->grant(Role::GERENTE_GENERAL, ['companies.manage']);

        $this->actingAs($gerente->fresh(), 'sanctum')->getJson('/api/v1/admin/companies')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/branches')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.permissions', ['companies.manage']);

        $this->grant(Role::GERENTE_GENERAL, []);
        $this->actingAs($gerente->fresh(), 'sanctum')->getJson('/api/v1/admin/companies')->assertForbidden();
    }

    public function test_administrator_role_is_locked(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', Role::ADMINISTRADOR)->value('id'), ['permissions' => []])
            ->assertUnprocessable();

        $this->assertSame(25, Role::where('slug', Role::ADMINISTRADOR)->first()->permissions()->count());
    }

    public function test_roles_manage_cannot_be_granted_to_other_roles(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', Role::GERENTE_GENERAL)->value('id'), ['permissions' => ['roles.manage']])
            ->assertUnprocessable()->assertJsonValidationErrors('permissions');
        $this->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', Role::GERENTE_GENERAL)->value('id'), ['permissions' => ['no.existe']])
            ->assertUnprocessable();
    }

    public function test_permission_changes_are_audited(): void
    {
        $this->grant(Role::SUPERVISOR_VENTAS, ['audit.view']);
        $this->grant(Role::SUPERVISOR_VENTAS, ['audit.view']); // sin cambios: no genera ruido

        $logs = AuditLog::where('event', 'role.permissions_changed')->get();
        $this->assertCount(1, $logs);
        $this->assertSame([], $logs[0]->old_values['permissions']);
        $this->assertSame(['audit.view'], $logs[0]->new_values['permissions']);
    }

    public function test_reseeding_keeps_grants_made_from_the_screen(): void
    {
        $this->grant(Role::GERENTE_GENERAL, ['users.manage']);

        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(['users.manage'], Role::where('slug', Role::GERENTE_GENERAL)->first()->permissions()->pluck('slug')->all());
    }

    public function test_legacy_organization_permission_is_migrated(): void
    {
        $legacy = Permission::create(['slug' => 'organization.manage', 'name' => 'Antiguo']);
        $role = Role::where('slug', Role::SUPERVISOR_VENTAS)->first();
        $role->permissions()->attach($legacy);

        $this->seed(RolePermissionSeeder::class);

        $this->assertEqualsCanonicalizing(['companies.manage', 'branches.manage'], $role->fresh()->permissions()->pluck('slug')->all());
        $this->assertNull(Permission::where('slug', 'organization.manage')->first());
    }

    public function test_only_administrator_creates_companies_but_others_with_permission_edit_their_own(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $this->grant(Role::GERENTE_GENERAL, ['companies.manage']);
        $gerente = $this->userWith(Role::GERENTE_GENERAL, [$mine]);

        $this->actingAs($gerente, 'sanctum')
            ->postJson('/api/v1/admin/companies', ['code' => 'NEW', 'name' => 'Nueva'])->assertForbidden();
        $this->putJson("/api/v1/admin/companies/{$mine->id}", ['code' => $mine->code, 'name' => 'Renombrada'])->assertOk();
        $this->putJson("/api/v1/admin/companies/{$other->id}", ['code' => $other->code, 'name' => 'x'])->assertNotFound();
    }

    public function test_branches_manage_limits_creation_to_companies_with_full_access(): void
    {
        [$full, $restricted, $outside] = Company::factory()->count(3)->create();
        $limited = Branch::factory()->create(['company_id' => $restricted->id]);
        $this->grant(Role::GERENTE_GENERAL, ['branches.manage']);
        $gerente = $this->userWith(Role::GERENTE_GENERAL, [$full, $restricted]);
        $gerente->branches()->attach($limited); // restringido a una sucursal de $restricted

        $this->actingAs($gerente, 'sanctum');
        $this->postJson('/api/v1/admin/branches', ['company_id' => $full->id, 'code' => 'N1', 'name' => 'Nueva'])->assertCreated();
        $this->postJson('/api/v1/admin/branches', ['company_id' => $restricted->id, 'code' => 'N2', 'name' => 'x'])->assertJsonValidationErrors('company_id');
        $this->postJson('/api/v1/admin/branches', ['company_id' => $outside->id, 'code' => 'N3', 'name' => 'x'])->assertJsonValidationErrors('company_id');
    }

    public function test_users_manage_without_admin_cannot_escalate(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $this->grant(Role::GERENTE_GENERAL, ['users.manage']);
        $manager = $this->userWith(Role::GERENTE_GENERAL, [$mine]);
        $vendedor = $this->userWith(Role::VENDEDOR, [$mine]);
        $foreign = $this->userWith(Role::VENDEDOR, [$other]);
        $vendedorRole = Role::where('slug', Role::VENDEDOR)->value('id');
        $adminRole = Role::where('slug', Role::ADMINISTRADOR)->value('id');
        $payload = fn (array $o = []) => ['username' => 'nuevo', 'name' => 'N', 'password' => 'Clave-segura-123', 'role_id' => $vendedorRole, 'company_ids' => [$mine->id], 'branch_ids' => [], ...$o];

        $this->actingAs($manager, 'sanctum');

        // Listado: ve a los de su ámbito, no a administradores ni a usuarios de otras compañías.
        $usernames = collect($this->getJson('/api/v1/admin/users')->assertOk()->json('data'))->pluck('username');
        $this->assertTrue($usernames->contains($vendedor->username));
        $this->assertFalse($usernames->contains($this->admin->username));
        $this->assertFalse($usernames->contains($foreign->username));

        // Roles asignables: sin Administrador.
        $this->assertNotContains('administrador', collect($this->getJson('/api/v1/admin/roles')->json('data'))->pluck('slug')->all());

        // Crear: ni rol Administrador ni compañías ajenas.
        $this->postJson('/api/v1/admin/users', $payload(['role_id' => $adminRole]))->assertJsonValidationErrors('role_id');
        $this->postJson('/api/v1/admin/users', $payload(['company_ids' => [$other->id]]))->assertJsonValidationErrors('company_ids');
        $this->postJson('/api/v1/admin/users', $payload())->assertCreated();

        // Editar: no a administradores ni a usuarios fuera de su ámbito.
        $this->putJson("/api/v1/admin/users/{$this->admin->id}", $payload(['username' => $this->admin->username, 'password' => '']))->assertNotFound();
        $this->putJson("/api/v1/admin/users/{$foreign->id}", $payload(['username' => $foreign->username, 'password' => '']))->assertNotFound();
        $this->getJson("/api/v1/admin/users/{$foreign->id}")->assertNotFound();
    }

    public function test_audit_view_without_admin_only_sees_own_companies(): void
    {
        [$mine, $other] = Company::factory()->count(2)->create();
        $this->grant(Role::SUPERVISOR_VENTAS, ['audit.view']);
        $supervisor = $this->userWith(Role::SUPERVISOR_VENTAS, [$mine]);
        AuditLog::query()->delete(); // descarta el ruido de la preparación
        AuditLog::create(['event' => 'branch.created', 'company_id' => $mine->id]);
        AuditLog::create(['event' => 'branch.created', 'company_id' => $other->id]);
        AuditLog::create(['event' => 'auth.login']); // sin compañía

        $this->actingAs($supervisor, 'sanctum')->getJson('/api/v1/admin/audit-logs')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.company_id', $mine->id);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertJsonCount(3, 'data');
    }
}
