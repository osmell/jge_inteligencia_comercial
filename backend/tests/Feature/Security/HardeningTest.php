<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\Company;
use App\Models\Customer;
use App\Models\Permission;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Defensas generales: cabeceras, límites de peticiones, archivos hostiles, datos sensibles y entradas maliciosas. */
class HardeningTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $this->seed(RolePermissionSeeder::class);

        return User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    public function test_every_response_carries_the_security_headers(): void
    {
        $response = $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('DENY', $response->headers->get('X-Frame-Options'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("default-src 'none'", (string) $response->headers->get('Content-Security-Policy'));
        $this->assertStringContainsString('camera=()', (string) $response->headers->get('Permissions-Policy'));
        $this->assertNull($response->headers->get('Strict-Transport-Security'), 'HSTS solo por HTTPS.');
    }

    public function test_https_responses_add_hsts(): void
    {
        $response = $this->withServerVariables(['HTTPS' => 'on'])->getJson('https://localhost/api/v1/auth/me');

        $this->assertStringContainsString('max-age=31536000', (string) $response->headers->get('Strict-Transport-Security'));
    }

    public function test_the_general_api_rate_limit_stops_a_flood(): void
    {
        config(['security.api_per_minute' => 3]);
        $admin = $this->admin();

        for ($i = 0; $i < 3; $i++) {
            $this->actingAs($admin, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
        }
        $this->actingAs($admin, 'sanctum')->getJson('/api/v1/auth/me')->assertStatus(429);
    }

    public function test_the_limit_is_per_person_not_shared(): void
    {
        config(['security.api_per_minute' => 2]);
        $one = $this->admin();
        $two = User::factory()->role(Role::ADMINISTRADOR)->create();

        $this->actingAs($one, 'sanctum')->getJson('/api/v1/auth/me');
        $this->actingAs($one, 'sanctum')->getJson('/api/v1/auth/me');
        $this->actingAs($one, 'sanctum')->getJson('/api/v1/auth/me')->assertStatus(429);
        $this->actingAs($two, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_password_spraying_from_one_address_is_stopped_even_across_different_users(): void
    {
        config(['security.login_per_ip_per_minute' => 3]);
        $this->seed(RolePermissionSeeder::class);

        foreach (['uno', 'dos', 'tres'] as $name) {
            $this->postJson('/api/v1/auth/login', ['username' => $name, 'password' => 'Clave-equivocada-1'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['username' => 'cuatro', 'password' => 'Clave-equivocada-1'])->assertStatus(429);
    }

    public function test_a_wrong_password_and_an_unknown_user_look_identical(): void
    {
        $user = $this->admin();

        $known = $this->postJson('/api/v1/auth/login', ['username' => $user->username, 'password' => 'Clave-equivocada-1']);
        $unknown = $this->postJson('/api/v1/auth/login', ['username' => 'no.existe', 'password' => 'Clave-equivocada-1']);

        $this->assertSame($known->getStatusCode(), $unknown->getStatusCode());
        $this->assertSame($known->json('errors.username'), $unknown->json('errors.username'), 'El mensaje no debe revelar si el usuario existe.');
    }

    public function test_the_login_response_never_exposes_credentials(): void
    {
        $user = $this->admin();
        $this->actingAs($user, 'sanctum');

        foreach (['auth/me', 'admin/users', 'admin/users/'.$user->id] as $uri) {
            $body = (string) $this->getJson("/api/v1/$uri")->getContent();
            $this->assertStringNotContainsString('password', $body, "GET /$uri expone el campo password.");
            $this->assertStringNotContainsString('remember_token', $body, "GET /$uri expone remember_token.");
            $this->assertStringNotContainsString($user->password, $body);
        }
    }

    public function test_the_secret_settings_are_never_returned(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');
        $this->putJson('/api/v1/whatsapp/settings', ['enabled' => true, 'phone_number_id' => '123', 'api_version' => 'v21.0', 'access_token' => 'TOKEN-SECRETO-XYZ', 'app_secret' => 'APPSECRET-XYZ', 'verify_token' => 'VERIFY-XYZ'])->assertOk();
        $this->putJson('/api/v1/webhook/settings', ['enabled' => false, 'url' => 'https://example.com/hook', 'secret' => 'WEBHOOK-SECRETO-XYZ', 'events' => []]);

        foreach (['whatsapp/settings', 'webhook/settings', 'mail/settings', 'audit-logs'] as $uri) {
            $body = (string) $this->getJson("/api/v1/$uri")->getContent();
            foreach (['TOKEN-SECRETO-XYZ', 'APPSECRET-XYZ', 'VERIFY-XYZ', 'WEBHOOK-SECRETO-XYZ'] as $secret) {
                $this->assertStringNotContainsString($secret, $body, "GET /$uri devuelve un secreto.");
            }
        }
    }

    /**
     * Archivo real en disco (no el «fake» de Laravel, que deduce el tipo por el nombre): así se prueba la detección por contenido.
     */
    private static function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($path, $content);

        return new UploadedFile($path, $name, null, null, true);
    }

    /** @return array<string, array{string, string}> */
    public static function hostileFiles(): array
    {
        return [
            'HTML disfrazado de PDF' => ['malo.pdf', '<html><script>alert(1)</script></html>'],
            'PHP disfrazado de imagen' => ['shell.png', '<?php system($_GET["c"]); ?>'],
            'SVG con script' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'ejecutable' => ['programa.exe', "MZ\x90\x00\x03"],
            'doble extensión' => ['factura.pdf.php', '<?php echo 1;'],
            'texto disfrazado de JPG' => ['foto.jpg', 'esto no es una imagen'],
        ];
    }

    #[DataProvider('hostileFiles')]
    public function test_a_quotation_attachment_rejects_files_that_are_not_what_they_claim(string $name, string $content): void
    {
        $file = self::realFile($name, $content);
        Storage::fake('quotations');
        $admin = $this->admin();
        $company = Company::factory()->create();
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'C1', 'name' => 'Cliente', 'source' => 'sales']);
        $quotation = Quotation::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'user_id' => $admin->id, 'sequence' => 1, 'number' => 'COT-000001', 'status' => 'draft', 'amount' => 1]);

        $this->actingAs($admin, 'sanctum')->post("/api/v1/quotations/{$quotation->id}/attachment", ['file' => $file], ['Accept' => 'application/json'])->assertStatus(422);

        $this->assertSame([], Storage::disk('quotations')->allFiles(), 'No debe guardarse nada.');
    }

    public function test_a_real_image_is_accepted_and_served_as_a_download_with_nosniff(): void
    {
        Storage::fake('quotations');
        $admin = $this->admin();
        $company = Company::factory()->create();
        $customer = Customer::create(['company_id' => $company->id, 'code' => 'C1', 'name' => 'Cliente', 'source' => 'sales']);
        $quotation = Quotation::create(['company_id' => $company->id, 'customer_id' => $customer->id, 'user_id' => $admin->id, 'sequence' => 1, 'number' => 'COT-000001', 'status' => 'draft', 'amount' => 1]);
        $png = self::realFile('foto.png', (string) base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGP4z8DwHwAFAAH/iZk9HQAAAABJRU5ErkJggg=='));

        $this->actingAs($admin, 'sanctum')->post("/api/v1/quotations/{$quotation->id}/attachment", ['file' => $png], ['Accept' => 'application/json'])->assertOk();
        $download = $this->get("/api/v1/quotations/{$quotation->id}/attachment");

        $download->assertOk();
        $this->assertSame('nosniff', $download->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('attachment', (string) $download->headers->get('Content-Disposition'));
    }

    public function test_an_import_rejects_anything_that_is_not_a_spreadsheet(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin, 'sanctum');

        foreach (['datos.csv' => "a,b\n1,2", 'datos.xlsx' => '<?php echo 1;', 'macro.xlsm' => 'PK'] as $name => $content) {
            $this->post('/api/v1/admin/imports', ['type' => 'sales', 'file' => self::realFile($name, $content)], ['Accept' => 'application/json'])->assertStatus(422);
        }
    }

    public function test_the_template_download_only_accepts_known_types(): void
    {
        $this->actingAs($this->admin(), 'sanctum');

        foreach (['..', '%2e%2e%2f%2e%2e%2fetc%2fpasswd', 'x;ls', 'sales%00.xlsx'] as $type) {
            $status = $this->get("/api/v1/admin/imports/templates/$type")->getStatusCode();
            $this->assertContains($status, [404, 422], "La plantilla «{$type}» respondió {$status}.");
        }
    }

    /** Cadenas típicas de inyección SQL, de comodines y de scripts: ninguna consulta de lectura puede romperse ni devolver un error de servidor. */
    public function test_hostile_input_in_every_read_endpoint_never_causes_a_server_error(): void
    {
        $admin = $this->admin();
        Role::where('slug', Role::ADMINISTRADOR)->first()?->permissions()->sync(Permission::pluck('id')->all());
        $payloads = ["' OR '1'='1", '1; DROP TABLE users; --', "%' --", '_%\\', '<script>alert(1)</script>', '../../etc/passwd', str_repeat('A', 5000), "\0", '-1', '9999999999999999999', '[]', '{"a":1}'];
        $keys = ['q', 'code', 'sort', 'dimension', 'status', 'type', 'severity', 'seller_id', 'company_id', 'branch_id', 'page', 'per_page', 'from', 'to', 'month', 'period', 'segment', 'abc', 'category', 'group', 'line', 'product_code', 'customer_code'];

        $routes = collect(Route::getRoutes()->getRoutes())->filter(fn (LaravelRoute $r) => str_starts_with($r->uri(), 'api/v1/') && in_array('GET', $r->methods(), true) && ! str_contains($r->uri(), 'export') && ! str_contains($r->uri(), 'broadcasting'));
        $checked = 0;
        foreach ($routes as $route) {
            $uri = preg_replace_callback('/\{([^}]+)\}/', fn ($m) => str_starts_with((string) ($route->wheres[$m[1]] ?? ''), '[') ? 'abc' : '1', $route->uri());
            foreach ($payloads as $payload) {
                $query = http_build_query(array_fill_keys($keys, $payload));
                $status = $this->actingAs($admin, 'sanctum')->getJson("/$uri?$query")->getStatusCode();
                $this->assertLessThan(500, $status, "GET /$uri con «".substr($payload, 0, 30).'» respondió '.$status);
            }
            $checked++;
        }

        $this->assertGreaterThan(80, $checked);
    }

    public function test_ops_status_flags_debug_mode_and_insecure_cookies_in_production(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        config(['app.debug' => true, 'session.secure' => false]);

        $this->assertSame(1, Artisan::call('ops:status', ['--json' => true]));
        $checks = json_decode(trim(Artisan::output()), true)['checks'];

        $this->assertFalse($checks['debug_off']['ok']);
        $this->assertFalse($checks['secure_cookie']['ok']);

        config(['app.debug' => false, 'session.secure' => true]);
        Artisan::call('ops:status', ['--json' => true]);
        $checks = json_decode(trim(Artisan::output()), true)['checks'];
        $this->assertTrue($checks['debug_off']['ok'] && $checks['secure_cookie']['ok']);
    }
}
