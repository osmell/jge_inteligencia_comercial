<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DevDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DevSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seed_creates_demo_data_for_every_role_and_is_idempotent(): void
    {
        $this->seed(DatabaseSeeder::class);
        $this->seed(DatabaseSeeder::class);

        $this->assertSame(2, Company::count());
        $this->assertSame(3, Branch::count());
        $this->assertSame(5, User::count());
        foreach (Role::all() as $role) {
            $this->assertGreaterThan(0, User::where('role_id', $role->id)->count(), $role->slug);
        }
        $this->assertTrue(Hash::check(DevDataSeeder::PASSWORD, User::where('username', 'vendedor1')->value('password')));
    }

    public function test_demo_users_see_only_their_scope(): void
    {
        $this->seed(DatabaseSeeder::class);

        $this->actingAs(User::where('username', 'vendedor1')->first(), 'sanctum')
            ->getJson('/api/v1/branches')->assertJsonCount(1, 'data');
        $this->actingAs(User::where('username', 'gerente')->first(), 'sanctum')
            ->getJson('/api/v1/branches')->assertJsonCount(3, 'data');
        $this->actingAs(User::where('username', 'admin')->first(), 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')->assertOk();
        $this->actingAs(User::where('username', 'supervisor')->first(), 'sanctum')
            ->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    }

    public function test_demo_data_is_not_seeded_in_production(): void
    {
        $this->app['env'] = 'production';

        // Se invocan directamente: db:seed pide confirmación interactiva en producción.
        $this->app->make(DatabaseSeeder::class)->run();
        $this->assertSame(0, User::count());
        $this->assertSame(4, Role::count());

        $this->expectException(RuntimeException::class);
        $this->app->make(DevDataSeeder::class)->run();
    }
}
