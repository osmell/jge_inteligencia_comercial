<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Models\AuditLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\Menu\MenuCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** «Menú por usuario»: la administración oculta opciones y el servidor lo hace cumplir; solo quita, nunca da, y solo la ve un administrador. */
class MenuAccessTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // El gerente de las pruebas tiene todos los permisos de las pantallas (menos los de administración exclusiva) para aislar el efecto del menú.
        Role::where('slug', Role::GERENTE_GENERAL)->first()->permissions()->sync(Permission::whereNotIn('slug', ['roles.manage'])->pluck('id'));
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
    }

    private function hide(User $user, array $keys): void
    {
        $user->forceFill(['hidden_menu' => $keys])->save();
    }

    /** ¿La ruta fue rechazada por el menú (y no por permisos u otra causa)? */
    private function blocked(User $as, string $url): bool
    {
        $this->app['auth']->forgetGuards();
        $response = $this->actingAs($as, 'sanctum')->getJson($url);

        return $response->getStatusCode() === 403 && $response->json('code') === 'menu_option_hidden';
    }

    public function test_by_default_nothing_is_hidden(): void
    {
        $this->assertFalse($this->blocked($this->manager, '/api/v1/credit/aging'));
        $this->assertSame([], $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()->json('data.hidden_menu'));
    }

    public function test_a_hidden_option_is_rejected_by_the_server_not_just_hidden_in_the_interface(): void
    {
        $this->hide($this->manager, ['credit']);

        $this->assertTrue($this->blocked($this->manager, '/api/v1/credit/aging'));
        $this->assertTrue($this->blocked($this->manager, '/api/v1/credit/invoices'));
        $this->assertFalse($this->blocked($this->manager, '/api/v1/inventory/stock'), 'Las demás opciones siguen.');
        $this->assertFalse($this->blocked($this->manager, '/api/v1/dashboard'), 'Inicio nunca se oculta.');
        $this->assertFalse($this->blocked($this->manager, '/api/v1/notifications/summary'));
    }

    public function test_shared_data_stays_while_any_option_that_uses_it_is_visible(): void
    {
        $this->hide($this->manager, ['credit']);
        $this->assertFalse($this->blocked($this->manager, '/api/v1/credit/customer'), 'La ficha del cliente (Clientes) sigue mostrando su crédito.');
        $this->assertFalse($this->blocked($this->manager, '/api/v1/credit/promises'));

        $this->hide($this->manager, ['credit', 'customers']);
        $this->assertTrue($this->blocked($this->manager, '/api/v1/credit/customer'), 'Ocultas todas las que lo usan, se bloquea.');
        $this->assertFalse($this->blocked($this->manager, '/api/v1/credit/promises'), 'Agenda también usa las promesas.');

        $this->hide($this->manager, ['credit', 'customers', 'agenda']);
        $this->assertTrue($this->blocked($this->manager, '/api/v1/credit/promises'));
    }

    public function test_each_report_is_hidden_on_its_own(): void
    {
        $this->hide($this->manager, ['report_products']);

        $this->assertTrue($this->blocked($this->manager, '/api/v1/reports/products?from=2026-10-01&to=2026-10-31'));
        $this->assertTrue($this->blocked($this->manager, '/api/v1/reports/products/export?from=2026-10-01&to=2026-10-31&format=pdf'));
        $this->assertFalse($this->blocked($this->manager, '/api/v1/reports/sellers?from=2026-10-01&to=2026-10-31'));
        $this->assertFalse($this->blocked($this->manager, '/api/v1/reports/context'), 'Los filtros los usan los demás reportes.');
    }

    public function test_hiding_every_report_also_blocks_their_shared_filters(): void
    {
        $this->hide($this->manager, ['report_sellers', 'report_operators', 'report_branches', 'report_products', 'report_conciliation']);

        $this->assertTrue($this->blocked($this->manager, '/api/v1/reports/context'));
    }

    public function test_an_administrator_is_never_restricted_even_with_a_stale_selection(): void
    {
        $this->hide($this->admin, ['credit']);

        $this->assertFalse($this->blocked($this->admin, '/api/v1/credit/aging'));
        $this->assertSame([], $this->admin->fresh()->hiddenMenu());
    }

    public function test_it_never_grants_what_the_role_does_not_allow(): void
    {
        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $this->hide($seller, ['sales']);

        // Sin permiso sigue siendo un 403 normal, no un «oculto por el menú».
        $this->assertFalse($this->blocked($seller, '/api/v1/admin/audit-logs'));
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/admin/audit-logs')->assertForbidden();
    }

    public function test_me_returns_what_is_hidden_so_the_interface_can_hide_it(): void
    {
        $this->hide($this->manager, ['credit', 'agenda']);

        $this->assertSame(['credit', 'agenda'], $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()->json('data.hidden_menu'));
    }

    public function test_the_administrator_sees_the_catalog_and_the_non_administrators_with_what_their_role_allows(): void
    {
        $seller = User::factory()->role(Role::VENDEDOR)->create();

        $data = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/menu-access')->assertOk()->json('data');

        $this->assertSame(MenuCatalog::keys(), array_column($data['options'], 'key'));
        $ids = array_column($data['users'], 'id');
        $this->assertContains($seller->id, $ids);
        $this->assertContains($this->manager->id, $ids);
        $this->assertNotContains($this->admin->id, $ids, 'Los administradores no se configuran.');
        $row = array_column($data['users'], null, 'id')[$seller->id];
        $this->assertNotContains('audit', $row['available']);
        $this->assertContains('credit', array_column($data['users'], null, 'id')[$this->manager->id]['available']);
    }

    public function test_the_administrator_saves_the_selection_and_it_takes_effect_and_is_audited(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/menu-access/{$this->manager->id}", ['hidden' => ['agenda', 'credit', 'agenda']])->assertUnprocessable();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/menu-access/{$this->manager->id}", ['hidden' => ['credit', 'agenda']])
            ->assertOk()->assertJsonPath('data.hidden', ['agenda', 'credit']);

        $this->assertSame(['agenda', 'credit'], $this->manager->fresh()->hiddenMenu());
        $this->assertTrue($this->blocked($this->manager->fresh(), '/api/v1/credit/aging'));
        $log = AuditLog::query()->where('event', 'user.updated')->where('auditable_id', $this->manager->id)->latest('id')->first();
        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/menu-access/{$this->manager->id}", ['hidden' => []])->assertOk();
        $this->assertNull($this->manager->fresh()->hidden_menu);
    }

    public function test_invalid_selections_are_refused(): void
    {
        foreach ([['hidden' => ['no_existe']], ['hidden' => 'credit'], ['hidden' => [['credit']]], ['hidden' => ["credit' or '1'='1"]], []] as $body) {
            $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/menu-access/{$this->manager->id}", $body)->assertUnprocessable();
        }
        $this->assertNull($this->manager->fresh()->hidden_menu);
    }

    public function test_an_administrator_cannot_be_restricted(): void
    {
        $other = User::factory()->role(Role::ADMINISTRADOR)->create();

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/menu-access/{$other->id}", ['hidden' => ['credit']])->assertUnprocessable();
    }

    public function test_only_administrators_can_see_or_change_it_even_with_every_permission(): void
    {
        // El gerente tiene todos los permisos de pantalla, incluido users.manage: aun así no es administrador.
        $this->assertTrue($this->manager->hasPermission('users.manage'));

        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/admin/menu-access')->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->putJson("/api/v1/admin/menu-access/{$this->manager->id}", ['hidden' => []])->assertForbidden();
        $this->actingAs($this->manager, 'sanctum')->putJson('/api/v1/admin/menu-access/999999', ['hidden' => []])->assertForbidden();   // 403, no 404: no se pueden sondear identificadores
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admin/menu-access')->assertUnauthorized();
    }

    public function test_a_person_cannot_lift_their_own_restriction(): void
    {
        $this->hide($this->manager, ['credit']);

        $this->actingAs($this->manager, 'sanctum')->putJson("/api/v1/admin/menu-access/{$this->manager->id}", ['hidden' => []])->assertForbidden();
        $this->assertSame(['credit'], $this->manager->fresh()->hiddenMenu());
    }
}
