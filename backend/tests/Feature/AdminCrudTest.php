<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCrudTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    private function roleId(string $slug): int
    {
        return Role::where('slug', $slug)->value('id');
    }

    private function userPayload(array $overrides = []): array
    {
        return [
            'username' => 'Mlopez',
            'name' => 'María López',
            'password' => 'Clave-segura-123',
            'role_id' => $this->roleId(Role::VENDEDOR),
            'company_ids' => [],
            'branch_ids' => [],
            ...$overrides,
        ];
    }

    public function test_non_admin_cannot_use_any_admin_crud_route(): void
    {
        $company = Company::factory()->create();
        $this->actingAs(User::factory()->role(Role::GERENTE_GENERAL)->create(), 'sanctum');

        $this->getJson('/api/v1/admin/companies')->assertForbidden();
        $this->postJson('/api/v1/admin/companies', ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->putJson("/api/v1/admin/companies/{$company->id}", ['code' => 'X', 'name' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/admin/branches')->assertForbidden();
        $this->getJson('/api/v1/admin/users')->assertForbidden();
        $this->postJson('/api/v1/admin/users', $this->userPayload())->assertForbidden();
        $this->assertDatabaseMissing('users', ['username' => 'mlopez']);
    }

    public function test_company_crud(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $id = $this->postJson('/api/v1/admin/companies', ['code' => 'JGE', 'name' => 'JGE Soluciones'])
            ->assertCreated()->json('data.id');
        $this->postJson('/api/v1/admin/companies', ['code' => 'JGE', 'name' => 'Otra'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->putJson("/api/v1/admin/companies/{$id}", ['code' => 'JGE', 'name' => 'JGE SA', 'is_active' => false])
            ->assertOk()->assertJsonPath('data.is_active', false);
        $this->getJson("/api/v1/admin/companies/{$id}")->assertOk()->assertJsonPath('data.name', 'JGE SA');
        $this->deleteJson("/api/v1/admin/companies/{$id}")->assertStatus(405);
    }

    public function test_branch_crud_and_company_is_immutable(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        [$a, $b] = Company::factory()->count(2)->create();

        $id = $this->postJson('/api/v1/admin/branches', ['company_id' => $a->id, 'code' => 'S01', 'name' => 'Central'])
            ->assertCreated()->json('data.id');
        // Mismo código en otra compañía: permitido; en la misma: rechazado.
        $this->postJson('/api/v1/admin/branches', ['company_id' => $b->id, 'code' => 'S01', 'name' => 'Central'])->assertCreated();
        $this->postJson('/api/v1/admin/branches', ['company_id' => $a->id, 'code' => 'S01', 'name' => 'Dup'])
            ->assertUnprocessable()->assertJsonValidationErrors('code');

        $this->putJson("/api/v1/admin/branches/{$id}", ['code' => 'S01', 'name' => 'Central 2'])->assertOk();
        $this->putJson("/api/v1/admin/branches/{$id}", ['company_id' => $b->id, 'code' => 'S01', 'name' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('company_id');
    }

    public function test_user_create_normalizes_username_hashes_password_and_syncs_scope(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $branch = Branch::factory()->create();

        $response = $this->postJson('/api/v1/admin/users', $this->userPayload([
            'company_ids' => [$branch->company_id],
            'branch_ids' => [$branch->id],
        ]))->assertCreated()
            ->assertJsonPath('data.username', 'mlopez')
            ->assertJsonPath('data.company_ids.0', $branch->company_id)
            ->assertJsonPath('data.branch_ids.0', $branch->id);

        $this->assertArrayNotHasKey('password', $response->json('data'));
        $user = User::where('username', 'mlopez')->first();
        $this->assertNotSame('Clave-segura-123', $user->password);
    }

    public function test_username_rules(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        User::factory()->create(['username' => 'mlopez']);

        $this->postJson('/api/v1/admin/users', $this->userPayload())->assertJsonValidationErrors('username'); // duplicado (case-insensitive)
        $this->postJson('/api/v1/admin/users', $this->userPayload(['username' => 'maria@jge.com']))->assertJsonValidationErrors('username');
        $this->postJson('/api/v1/admin/users', $this->userPayload(['username' => 'con espacio']))->assertJsonValidationErrors('username');
    }

    public function test_weak_password_is_rejected(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $this->postJson('/api/v1/admin/users', $this->userPayload(['password' => 'corta1']))->assertJsonValidationErrors('password');
        $this->postJson('/api/v1/admin/users', $this->userPayload(['password' => 'sololetrasaquihay']))->assertJsonValidationErrors('password');
    }

    public function test_branch_must_belong_to_an_assigned_company(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $branch = Branch::factory()->create();

        $this->postJson('/api/v1/admin/users', $this->userPayload(['branch_ids' => [$branch->id]]))
            ->assertUnprocessable()->assertJsonValidationErrors('branch_ids');
    }

    public function test_update_keeps_password_when_omitted_and_changes_it_when_sent(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $user = User::factory()->create(['username' => 'mlopez', 'password' => 'Original-pass-1']);
        $hash = $user->password;
        $payload = $this->userPayload(['username' => 'mlopez']);
        unset($payload['password']);

        $this->putJson("/api/v1/admin/users/{$user->id}", $payload)->assertOk();
        $this->assertSame($hash, $user->fresh()->password);

        $this->putJson("/api/v1/admin/users/{$user->id}", [...$payload, 'password' => 'Nueva-clave-456'])->assertOk();
        $this->assertNotSame($hash, $user->fresh()->password);
    }

    public function test_admin_cannot_lock_themselves_out(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $base = ['username' => $this->admin->username, 'name' => 'Yo', 'role_id' => $this->admin->role_id, 'company_ids' => [], 'branch_ids' => []];

        $this->putJson("/api/v1/admin/users/{$this->admin->id}", [...$base, 'is_active' => false])
            ->assertJsonValidationErrors('is_active');
        $this->putJson("/api/v1/admin/users/{$this->admin->id}", [...$base, 'role_id' => $this->roleId(Role::VENDEDOR)])
            ->assertJsonValidationErrors('role_id');
    }

    public function test_deactivated_user_loses_access_with_existing_session(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/companies')->assertOk();

        $user->update(['is_active' => false]);

        $this->actingAs($user->fresh(), 'sanctum')->getJson('/api/v1/companies')->assertForbidden();
    }

    public function test_validation_messages_are_in_spanish_with_friendly_field_names(): void
    {
        $this->actingAs($this->admin, 'sanctum');

        $errors = $this->postJson('/api/v1/admin/users', [])->assertUnprocessable()->json('errors');

        $this->assertSame('El campo usuario es obligatorio.', $errors['username'][0]);
        $this->assertSame('El campo nombre es obligatorio.', $errors['name'][0]);
        $this->assertSame('El campo contraseña es obligatorio.', $errors['password'][0]);

        User::factory()->create(['username' => 'mlopez']);
        $dup = $this->postJson('/api/v1/admin/users', $this->userPayload())->json('errors.username.0');
        $this->assertSame('El valor de usuario ya está en uso.', $dup);
    }
}
