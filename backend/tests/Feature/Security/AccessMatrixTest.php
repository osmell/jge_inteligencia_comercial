<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Recorre TODAS las rutas de la API: nadie sin sesión entra a ninguna que no sea pública, y quien tiene sesión pero ningún permiso queda
 * fuera de toda ruta que exija permiso. Si se agrega una ruta sin permiso, esta prueba falla hasta que se decida a propósito (lista blanca).
 */
class AccessMatrixTest extends TestCase
{
    use RefreshDatabase;

    /** Rutas que cualquiera puede llamar sin sesión: el inicio de sesión y los webhooks (que se validan con su firma). */
    private const PUBLIC = ['api/v1/auth/login', 'api/v1/webhooks/whatsapp'];

    /** Rutas con sesión pero sin permiso específico: datos de la propia persona o catálogos de su ámbito. Agregar una exige revisión. */
    private const ANY_SIGNED_IN_USER = [
        'POST api/v1/auth/logout', 'POST api/v1/auth/password', 'GET api/v1/auth/me', 'GET api/v1/branches', 'GET api/v1/broadcasting/auth', 'GET api/v1/companies',
        'GET api/v1/dashboard', 'GET api/v1/dashboard/{section}', 'GET api/v1/notifications', 'GET api/v1/notifications/preferences',
        'PUT api/v1/notifications/preferences', 'POST api/v1/notifications/seen-all', 'GET api/v1/notifications/summary',
        'POST api/v1/notifications/test', 'POST api/v1/notifications/{notification}/dismiss', 'POST api/v1/notifications/{notification}/seen',
        'GET api/v1/realtime/config',
    ];

    /** @return Collection<int, LaravelRoute> */
    private function apiRoutes(): Collection
    {
        return collect(Route::getRoutes()->getRoutes())->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/'))->values();
    }

    /** Dirección con un valor de ejemplo por parámetro que cumpla la restricción de la ruta (número, o una de las opciones permitidas). */
    private function url(LaravelRoute $route): string
    {
        return '/'.preg_replace_callback('/\{([^}]+)\}/', function (array $m) use ($route) {
            $pattern = (string) ($route->wheres[$m[1]] ?? '');
            if ($pattern === '' || preg_match('/^\[?0-9|\\d/', $pattern)) {
                return '1';
            }
            if (str_starts_with($pattern, '[')) {
                return 'abc';      // clase de caracteres (por ejemplo [a-z]+)
            }
            $first = explode('|', trim($pattern, '()'))[0];

            return preg_replace('/[^A-Za-z0-9_-]/', '', $first) ?: 'x';
        }, $route->uri());
    }

    /** @return list<string> */
    private function permissionsOf(LaravelRoute $route): array
    {
        return collect($route->gatherMiddleware())->filter(fn ($m) => is_string($m) && str_starts_with($m, 'permission:'))->map(fn ($m) => substr($m, 11))->values()->all();
    }

    private function methodOf(LaravelRoute $route): string
    {
        return collect($route->methods())->first(fn ($m) => $m !== 'HEAD') ?? 'GET';
    }

    public function test_the_routes_that_exist_are_the_routes_we_expect_to_cover(): void
    {
        $this->assertGreaterThan(150, $this->apiRoutes()->count(), 'La matriz debe cubrir toda la API.');
    }

    public function test_no_route_is_reachable_without_a_session_except_the_public_ones(): void
    {
        foreach ($this->apiRoutes() as $route) {
            if (in_array($route->uri(), self::PUBLIC, true)) {
                continue;
            }
            $status = $this->json($this->methodOf($route), $this->url($route))->getStatusCode();
            $this->assertSame(401, $status, "{$this->methodOf($route)} /{$route->uri()} respondió $status sin sesión (se esperaba 401).");
        }
    }

    public function test_a_signed_in_user_without_permissions_is_refused_by_every_route_that_requires_one(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $role = Role::where('slug', Role::VENDEDOR)->firstOrFail();
        $role->permissions()->sync([]);
        $user = User::factory()->create(['role_id' => $role->id]);

        $checked = 0;
        foreach ($this->apiRoutes() as $route) {
            if ($this->permissionsOf($route) === []) {
                continue;
            }
            $status = $this->actingAs($user, 'sanctum')->json($this->methodOf($route), $this->url($route))->getStatusCode();
            $this->assertSame(403, $status, "{$this->methodOf($route)} /{$route->uri()} (exige ".implode(' + ', $this->permissionsOf($route)).") respondió $status a un usuario sin permisos.");
            $checked++;
        }

        $this->assertGreaterThan(120, $checked);
    }

    public function test_the_routes_without_a_permission_are_exactly_the_reviewed_ones(): void
    {
        $withoutPermission = $this->apiRoutes()
            ->filter(fn (LaravelRoute $r) => $this->permissionsOf($r) === [] && ! in_array($r->uri(), self::PUBLIC, true))
            ->map(fn (LaravelRoute $r) => $this->methodOf($r).' '.$r->uri())
            ->sort()->values()->all();

        $expected = self::ANY_SIGNED_IN_USER;
        sort($expected);
        $this->assertSame($expected, $withoutPermission, 'Hay una ruta nueva sin permiso (o se quitó una). Si es intencional, agréguela a ANY_SIGNED_IN_USER tras revisarla.');
    }

    public function test_an_inactive_user_with_a_valid_session_is_refused(): void
    {
        $this->seed(RolePermissionSeeder::class);
        $user = User::factory()->role(Role::ADMINISTRADOR)->inactive()->create();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/users')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/companies')->assertStatus(403);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/broadcasting/auth')->assertStatus(403);   // tampoco recibe tiempo real
    }

    /** Rutas que una cuenta obligada a cambiar su clave sí puede usar: ver su perfil, cambiar la clave y salir. */
    private const ALLOWED_WITH_TEMPORARY_PASSWORD = ['GET api/v1/auth/me', 'POST api/v1/auth/logout', 'POST api/v1/auth/password'];

    public function test_an_account_with_a_temporary_password_can_use_nothing_but_the_three_allowed_routes(): void
    {
        $this->seed(RolePermissionSeeder::class);
        // Con TODOS los permisos: si algo se bloquea, es por la clave temporal y no por falta de permisos.
        $user = User::factory()->role(Role::ADMINISTRADOR)->create(['must_change_password' => true]);

        $blocked = 0;
        foreach ($this->apiRoutes() as $route) {
            $key = $this->methodOf($route).' '.$route->uri();
            if (in_array($route->uri(), self::PUBLIC, true) || in_array($key, self::ALLOWED_WITH_TEMPORARY_PASSWORD, true) || $route->uri() === 'api/v1/auth/login') {
                continue;
            }
            $response = $this->actingAs($user, 'sanctum')->json($this->methodOf($route), $this->url($route));
            $this->assertSame(403, $response->getStatusCode(), "$key respondió {$response->getStatusCode()} a una cuenta con clave temporal.");
            $this->assertSame('password_change_required', $response->json('code'), "$key no devolvió el código password_change_required.");
            $blocked++;
        }

        $this->assertGreaterThan(170, $blocked);
        foreach (['GET api/v1/auth/me'] as $allowed) {
            $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
        }
    }

    public function test_the_whatsapp_webhook_refuses_unsigned_calls(): void
    {
        $this->postJson('/api/v1/webhooks/whatsapp', ['entry' => []])->assertForbidden();
        $this->getJson('/api/v1/webhooks/whatsapp?hub_mode=subscribe&hub_verify_token=adivinado&hub_challenge=1')->assertForbidden();
    }
}
