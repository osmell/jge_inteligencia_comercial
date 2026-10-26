<?php

declare(strict_types=1);

namespace Tests\Feature\Menu;

use App\Services\Menu\MenuCatalog;
use App\Services\Reports\Output\ReportCatalog;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Vigila que el catálogo del menú no se desfase: toda ruta de la API tiene dueño (una o varias opciones) o es una excepción declarada,
 * las llaves y permisos existen, y las llaves coinciden con las del menú de la interfaz. Una ruta nueva sin declarar hace fallar esto
 * en lugar de quedar sin bloqueo cuando la administración oculte una opción.
 */
class MenuCatalogTest extends TestCase
{
    /** @return list<string> */
    private function apiPaths(): array
    {
        $paths = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            /** @var LaravelRoute $route */
            if (! str_starts_with($route->uri(), 'api/v1/')) {
                continue;
            }
            $uri = substr($route->uri(), strlen('api/v1/'));
            // `{report}` solo admite los reportes del catálogo; cualquier otro parámetro se sustituye por un número.
            $variants = str_contains($uri, '{report}') ? array_map(fn (string $r) => str_replace('{report}', $r, $uri), ReportCatalog::REPORTS) : [$uri];
            foreach ($variants as $variant) {
                $paths[] = (string) preg_replace('#\{[^}]+\}#', '1', $variant);
            }
        }

        return array_values(array_unique($paths));
    }

    public function test_every_api_route_has_an_owner_or_a_declared_exception(): void
    {
        $catalog = new MenuCatalog;
        $unknown = array_values(array_filter($this->apiPaths(), fn (string $p) => ! $catalog->isKnown($p)));

        $this->assertSame([], $unknown, 'Estas rutas no pertenecen a ninguna opción del menú: agréguelas a MenuCatalog::CLAIMS (o a EXEMPT si son parte de entrar al sistema).');
    }

    public function test_claims_and_options_are_consistent(): void
    {
        foreach (MenuCatalog::CLAIMS as $prefix => $owners) {
            $this->assertNotSame([], $owners, $prefix);
            foreach ($owners as $key) {
                $this->assertTrue(MenuCatalog::exists($key), "«{$prefix}» menciona la opción inexistente «{$key}».");
            }
        }
        $claimed = array_unique(array_merge(...array_values(MenuCatalog::CLAIMS)));
        $this->assertSame([], array_values(array_diff(MenuCatalog::keys(), $claimed)), 'Hay opciones que no poseen ninguna ruta.');

        $permissions = array_keys(RolePermissionSeeder::PERMISSIONS);
        foreach (MenuCatalog::OPTIONS as $key => $option) {
            $this->assertContains($option['permission'], $permissions, "La opción «{$key}» exige un permiso que no existe.");
        }
    }

    public function test_the_most_specific_route_wins(): void
    {
        $catalog = new MenuCatalog;

        $this->assertSame(['agenda'], $catalog->owners('customers/agenda'));
        $this->assertSame(['follow_up'], $catalog->owners('customers/follow-up-kpis'));
        $this->assertSame(['customers'], $catalog->owners('customers/profile'));
        $this->assertSame(['customers', 'quotations'], $catalog->owners('customers/12/whatsapp'));
        $this->assertSame(['report_products'], $catalog->owners('reports/products/sellers/3'));
        $this->assertSame(['report_sellers'], $catalog->owners('reports/sellers/export'));
        $this->assertSame([], $catalog->owners('conversations/unread'), 'La insignia de mensajes sin leer la pide toda sesión.');
        $this->assertSame(['conversations'], $catalog->owners('conversations/5/messages'));
        $this->assertSame([], $catalog->owners('dashboard/sales'));
        $this->assertSame([], $catalog->owners('creditx/aging'), 'Un prefijo se compara por segmentos completos.');
    }

    public function test_the_keys_match_the_ones_in_the_interface_menu(): void
    {
        $file = base_path('../frontend/src/components/navigation.ts');
        if (! is_file($file)) {
            $this->markTestSkipped('El código de la interfaz no está disponible en este entorno.');
        }

        preg_match_all("/key: '([a-z_]+)'/", (string) file_get_contents($file), $found);
        $this->assertEqualsCanonicalizing(MenuCatalog::keys(), $found[1], 'Las llaves de navigation.ts y de MenuCatalog::OPTIONS deben ser las mismas.');
    }
}
