<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->actingAs($this->admin, 'sanctum');
        AuditLog::query()->delete(); // descarta el ruido del propio setUp (permitido solo vía query builder)
    }

    public function test_company_changes_are_audited_with_old_and_new_values(): void
    {
        $id = $this->postJson('/api/v1/admin/companies', ['code' => 'JGE', 'name' => 'JGE'])->json('data.id');
        $this->putJson("/api/v1/admin/companies/{$id}", ['code' => 'JGE', 'name' => 'JGE SA'])->assertOk();

        $created = AuditLog::where('event', 'company.created')->first();
        $this->assertSame($this->admin->id, $created->user_id);
        $this->assertSame($id, $created->company_id);
        $this->assertSame('JGE', $created->new_values['name']);

        $updated = AuditLog::where('event', 'company.updated')->first();
        $this->assertSame(['name' => 'JGE'], $updated->old_values);
        $this->assertSame(['name' => 'JGE SA'], $updated->new_values);
    }

    public function test_branch_audit_records_its_company(): void
    {
        $company = Company::factory()->create();
        $this->postJson('/api/v1/admin/branches', ['company_id' => $company->id, 'code' => 'S1', 'name' => 'Central'])->assertCreated();

        $this->assertSame($company->id, AuditLog::where('event', 'branch.created')->value('company_id'));
    }

    public function test_user_audit_never_stores_password(): void
    {
        $id = $this->postJson('/api/v1/admin/users', [
            'username' => 'mlopez', 'name' => 'María', 'password' => 'Clave-segura-123',
            'role_id' => Role::where('slug', Role::VENDEDOR)->value('id'), 'company_ids' => [], 'branch_ids' => [],
        ])->assertCreated()->json('data.id');

        $this->putJson("/api/v1/admin/users/{$id}", [
            'username' => 'mlopez', 'name' => 'María', 'password' => 'Otra-clave-456',
            'role_id' => Role::where('slug', Role::VENDEDOR)->value('id'), 'company_ids' => [], 'branch_ids' => [],
        ])->assertOk();

        $this->assertSame('[cambiada]', AuditLog::where('event', 'user.created')->first()->new_values['password']);
        $this->assertSame('[cambiada]', AuditLog::where('event', 'user.updated')->first()->new_values['password']);
        $this->assertStringNotContainsString('Clave-segura', AuditLog::all()->toJson());
        $this->assertStringNotContainsString('Otra-clave', AuditLog::all()->toJson());
    }

    public function test_role_change_and_scope_change_are_audited(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['username' => 'mlopez']);
        $gerente = Role::where('slug', Role::GERENTE_GENERAL)->value('id');
        AuditLog::query()->delete();

        $this->putJson("/api/v1/admin/users/{$user->id}", [
            'username' => 'mlopez', 'name' => $user->name, 'role_id' => $gerente,
            'company_ids' => [$branch->company_id], 'branch_ids' => [$branch->id],
        ])->assertOk();

        $role = AuditLog::where('event', 'user.updated')->first();
        $this->assertSame($gerente, $role->new_values['role_id']);

        $scope = AuditLog::where('event', 'user.scope_changed')->first();
        $this->assertSame([], $scope->old_values['company_ids']);
        $this->assertSame([$branch->company_id], $scope->new_values['company_ids']);
        $this->assertSame([$branch->id], $scope->new_values['branch_ids']);
    }

    public function test_unchanged_scope_and_login_timestamp_do_not_create_noise(): void
    {
        $user = User::factory()->create();
        AuditLog::query()->delete();

        $user->forceFill(['last_login_at' => now()])->save();

        $this->assertSame(0, AuditLog::count());
    }

    public function test_audit_logs_cannot_be_modified_or_deleted(): void
    {
        $log = AuditLog::create(['event' => 'test.event']);

        $log->update(['event' => 'tampered']);
        $this->assertSame('test.event', $log->fresh()->event);

        $log->delete();
        $this->assertNotNull(AuditLog::find($log->id));
    }

    public function test_audit_endpoint_filters_by_event(): void
    {
        $this->postJson('/api/v1/admin/companies', ['code' => 'A', 'name' => 'A']);

        $this->getJson('/api/v1/admin/audit-logs?event=company.created')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.event', 'company.created');
    }
}
