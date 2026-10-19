<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        // Sanctum solo activa sesión/cookies para peticiones originadas en el SPA.
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173/');
    }

    private function user(array $attrs = []): User
    {
        return User::factory()->create(['username' => 'jperez', 'password' => 'clave-segura-1', ...$attrs]);
    }

    public function test_login_with_username_and_password(): void
    {
        $user = $this->user(['role_id' => Role::where('slug', Role::ADMINISTRADOR)->value('id')]);
        $company = Company::factory()->create();
        $user->companies()->attach($company);

        $this->postJson('/api/v1/auth/login', ['username' => 'jperez', 'password' => 'clave-segura-1'])
            ->assertOk()
            ->assertJsonPath('data.username', 'jperez')
            ->assertJsonPath('data.role.slug', 'administrador')
            ->assertJsonPath('data.companies.0.code', $company->code)
            ->assertJsonCount(4, 'data.permissions');

        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.username', 'jperez');
        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.login', 'user_id' => $user->id]);
    }

    public function test_username_is_case_insensitive(): void
    {
        $this->user();

        $this->postJson('/api/v1/auth/login', ['username' => ' JPerez ', 'password' => 'clave-segura-1'])->assertOk();
    }

    public function test_email_cannot_be_used_to_login(): void
    {
        $this->user(['email' => 'jperez@example.com']);

        $this->postJson('/api/v1/auth/login', ['username' => 'jperez@example.com', 'password' => 'clave-segura-1'])
            ->assertUnprocessable();
    }

    public function test_wrong_password_is_rejected_and_audited(): void
    {
        $this->user();

        $this->postJson('/api/v1/auth/login', ['username' => 'jperez', 'password' => 'mala'])
            ->assertUnprocessable()->assertJsonValidationErrors('username');
        $this->postJson('/api/v1/auth/login', ['username' => 'noexiste', 'password' => 'x'])->assertUnprocessable();

        $this->assertSame(2, AuditLog::where('event', 'auth.login_failed')->count());
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->user(['is_active' => false]);

        $this->postJson('/api/v1/auth/login', ['username' => 'jperez', 'password' => 'clave-segura-1'])
            ->assertUnprocessable();
    }

    public function test_login_is_locked_after_too_many_attempts(): void
    {
        $this->user();
        RateLimiter::clear('jperez|127.0.0.1');

        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['username' => 'jperez', 'password' => 'mala'])->assertUnprocessable();
        }

        $this->postJson('/api/v1/auth/login', ['username' => 'jperez', 'password' => 'clave-segura-1'])
            ->assertStatus(429);
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.locked', 'username' => 'jperez']);
    }

    public function test_logout_ends_session(): void
    {
        $this->user();
        $this->postJson('/api/v1/auth/login', ['username' => 'jperez', 'password' => 'clave-segura-1'])->assertOk();

        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.logout']);
    }
}
