<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guest_cannot_reach_protected_routes(): void
    {
        $this->getJson('/api/v1/companies')->assertUnauthorized();
        $this->getJson('/api/v1/admin/audit-logs')->assertUnauthorized();
    }

    public function test_non_admin_roles_cannot_call_admin_routes(): void
    {
        foreach ([Role::VENDEDOR, Role::SUPERVISOR_VENTAS, Role::GERENTE_GENERAL] as $role) {
            $this->actingAs(User::factory()->role($role)->create(), 'sanctum')
                ->getJson('/api/v1/admin/audit-logs')
                ->assertForbidden();
        }
    }

    public function test_administrator_can_call_admin_routes(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRADOR)->create(), 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertOk();
    }

    public function test_inactive_user_with_permission_is_denied(): void
    {
        $this->actingAs(User::factory()->role(Role::ADMINISTRADOR)->inactive()->create(), 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')
            ->assertForbidden();
    }

    public function test_administrator_sees_every_company_and_branch(): void
    {
        Branch::factory()->count(3)->create();

        $this->actingAs(User::factory()->role(Role::ADMINISTRADOR)->create(), 'sanctum')
            ->getJson('/api/v1/companies')->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/branches')->assertJsonCount(3, 'data');
    }

    public function test_user_only_sees_assigned_companies(): void
    {
        [$a, $b] = Company::factory()->count(2)->create();
        Branch::factory()->create(['company_id' => $a->id]);
        Branch::factory()->create(['company_id' => $b->id]);
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($a);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/companies')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $this->getJson('/api/v1/branches')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.company_id', $a->id);
    }

    public function test_branch_assignment_restricts_within_its_company(): void
    {
        $company = Company::factory()->create();
        [$s1, $s2] = Branch::factory()->count(2)->create(['company_id' => $company->id]);
        $user = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $user->companies()->attach($company);
        $user->branches()->attach($s1);

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/branches')
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $s1->id);
        $this->assertFalse(Branch::whereKey($s2->id)->exists());
    }

    public function test_company_without_branch_assignments_shows_all_its_branches(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        Branch::factory()->count(2)->create(['company_id' => $a->id]);
        [$b1] = Branch::factory()->count(2)->create(['company_id' => $b->id]);
        $user = User::factory()->create();
        $user->companies()->attach([$a->id, $b->id]);
        $user->branches()->attach($b1); // restringe solo la compañía B

        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/branches')
            ->assertJsonCount(3, 'data');
    }

    public function test_user_without_assignments_sees_nothing(): void
    {
        Branch::factory()->count(2)->create();

        $this->actingAs(User::factory()->create(), 'sanctum')
            ->getJson('/api/v1/companies')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/branches')->assertJsonCount(0, 'data');
    }

    public function test_assignment_to_branch_of_unassigned_company_grants_nothing(): void
    {
        $other = Branch::factory()->create();
        $user = User::factory()->create();
        $user->branches()->attach($other);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/branches')->assertJsonCount(0, 'data');
    }
}
