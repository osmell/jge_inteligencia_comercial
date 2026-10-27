<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\DefaultRoleGrantsSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DefaultRoleGrantsTest extends TestCase
{
    use RefreshDatabase;

    /** @return list<string> */
    private function slugs(string $role): array
    {
        return Role::where('slug', $role)->firstOrFail()->permissions()->pluck('slug')->sort()->values()->all();
    }

    public function test_every_default_permission_exists_and_each_role_receives_its_list(): void
    {
        $this->seed(DefaultRoleGrantsSeeder::class);

        foreach (DefaultRoleGrantsSeeder::GRANTS as $role => $expected) {
            $this->assertSame(collect($expected)->sort()->values()->all(), $this->slugs($role), $role);
        }
        $this->assertEqualsCanonicalizing(array_keys(RolePermissionSeeder::PERMISSIONS), Role::where('slug', Role::ADMINISTRADOR)->firstOrFail()->permissions()->pluck('slug')->all());
    }

    public function test_configuration_is_for_administration_only_and_managers_never_write_to_clients(): void
    {
        $this->seed(DefaultRoleGrantsSeeder::class);
        $adminOnly = ['users.manage', 'roles.manage', 'companies.manage', 'branches.manage', 'mail.manage', 'whatsapp.manage', 'webhooks.manage', 'automations.manage', 'imports.manage', 'audit.view'];

        foreach ([Role::GERENTE_GENERAL, Role::SUPERVISOR_VENTAS, Role::VENDEDOR] as $role) {
            $this->assertSame([], array_values(array_intersect($adminOnly, $this->slugs($role))), $role);
        }
        $this->assertNotContains('conversations.reply', $this->slugs(Role::GERENTE_GENERAL));
        $this->assertNotContains('inventory.costs', $this->slugs(Role::VENDEDOR));
    }

    public function test_it_only_adds_and_can_be_repeated(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $vendedor = Role::where('slug', Role::VENDEDOR)->firstOrFail();
        $vendedor->permissions()->attach(Permission::where('slug', 'reports.view')->value('id')); // otorgado a mano por administración

        $this->seed(DefaultRoleGrantsSeeder::class);
        $this->seed(DefaultRoleGrantsSeeder::class);

        $this->assertContains('reports.view', $this->slugs(Role::VENDEDOR));
        $this->assertCount(count(DefaultRoleGrantsSeeder::GRANTS[Role::VENDEDOR]) + 1, $this->slugs(Role::VENDEDOR));
    }
}
