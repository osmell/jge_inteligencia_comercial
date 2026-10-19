<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_creates_four_roles_and_admin_permissions(): void
    {
        $this->seed(RolePermissionSeeder::class);

        $this->assertSame(4, Role::count());
        $admin = Role::where('slug', Role::ADMINISTRADOR)->first();
        $this->assertSame(7, $admin->permissions()->count());
        $this->assertSame(0, Role::where('slug', Role::VENDEDOR)->first()->permissions()->count());
    }

    public function test_username_must_be_unique(): void
    {
        User::factory()->create(['username' => 'jperez']);

        $this->expectException(QueryException::class);
        User::factory()->create(['username' => 'jperez']);
    }

    public function test_password_is_hashed(): void
    {
        $user = User::factory()->create(['password' => 'secreto-123']);

        $this->assertNotSame('secreto-123', $user->password);
    }

    public function test_user_scope_relations(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create();
        $user->companies()->attach($branch->company_id);
        $user->branches()->attach($branch->id);

        $this->assertTrue($user->companies->first()->is($branch->company));
        $this->assertTrue($user->branches->first()->is($branch));
    }

    public function test_branch_code_unique_per_company_only(): void
    {
        $a = Company::factory()->create();
        $b = Company::factory()->create();
        Branch::factory()->create(['company_id' => $a->id, 'code' => 'S01']);
        Branch::factory()->create(['company_id' => $b->id, 'code' => 'S01']);

        $this->expectException(QueryException::class);
        Branch::factory()->create(['company_id' => $a->id, 'code' => 'S01']);
    }
}
