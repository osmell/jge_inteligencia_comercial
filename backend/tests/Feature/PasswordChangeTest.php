<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasswordIsCurrent;
use App\Models\AuditLog;
use App\Models\Company;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** Cambio de clave obligatorio: la clave que fija administración es temporal y se cambia al entrar. */
class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPORARY = 'Temporal-12345';

    private const NEW = 'Definitiva-67890';

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173/');   // la interfaz: petición con sesión
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
    }

    /** @return array<string, mixed> */
    private function newUserBody(string $username = 'nuevo.usuario'): array
    {
        return ['username' => $username, 'name' => 'Nuevo Usuario', 'password' => self::TEMPORARY, 'role_id' => Role::where('slug', Role::VENDEDOR)->value('id'), 'is_active' => true, 'company_ids' => [], 'branch_ids' => []];
    }

    private function temporaryUser(): User
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', $this->newUserBody())->assertCreated()->assertJsonPath('data.must_change_password', true);
        Auth::forgetGuards();

        return User::where('username', 'nuevo.usuario')->firstOrFail();
    }

    public function test_an_account_created_by_administration_must_change_its_password(): void
    {
        $user = $this->temporaryUser();

        $this->assertTrue($user->must_change_password);
        $this->assertNull($user->password_changed_at);
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('data.must_change_password', true);
    }

    public function test_existing_and_seeded_accounts_are_not_forced(): void
    {
        $this->assertFalse($this->admin->fresh()->must_change_password);
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/auth/me')->assertJsonPath('data.must_change_password', false);
    }

    public function test_a_flagged_account_can_sign_in_but_cannot_use_anything_else(): void
    {
        $user = $this->temporaryUser();
        $this->postJson('/api/v1/auth/login', ['username' => 'nuevo.usuario', 'password' => self::TEMPORARY])->assertOk()->assertJsonPath('data.must_change_password', true);
        Auth::forgetGuards();

        foreach (['companies', 'branches', 'dashboard', 'notifications', 'quotations', 'customers', 'conversations'] as $uri) {
            $this->actingAs($user, 'sanctum')->getJson("/api/v1/$uri")->assertForbidden()->assertJsonPath('code', EnsurePasswordIsCurrent::CODE);
        }
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/broadcasting/auth')->assertForbidden();
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/auth/me')->assertOk();
    }

    public function test_the_flagged_account_can_sign_out(): void
    {
        $user = $this->temporaryUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/logout')->assertNoContent();
    }

    public function test_changing_the_password_lifts_the_restriction_and_the_new_password_works(): void
    {
        $user = $this->temporaryUser();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/password', ['current_password' => self::TEMPORARY, 'password' => self::NEW, 'password_confirmation' => self::NEW])
            ->assertOk()->assertJsonPath('data.must_change_password', false);

        $fresh = $user->fresh();
        $this->assertFalse($fresh->must_change_password);
        $this->assertNotNull($fresh->password_changed_at);
        $this->assertTrue(Hash::check(self::NEW, $fresh->password));
        $this->actingAs($fresh, 'sanctum')->getJson('/api/v1/companies')->assertOk();

        Auth::forgetGuards();
        $this->postJson('/api/v1/auth/login', ['username' => 'nuevo.usuario', 'password' => self::TEMPORARY])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['username' => 'nuevo.usuario', 'password' => self::NEW])->assertOk()->assertJsonPath('data.must_change_password', false);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badChanges(): array
    {
        return [
            'clave actual incorrecta' => [['current_password' => 'Equivocada-1234', 'password' => self::NEW, 'password_confirmation' => self::NEW], 'current_password'],
            'confirmación distinta' => [['current_password' => self::TEMPORARY, 'password' => self::NEW, 'password_confirmation' => 'Otra-cosa-12345'], 'password'],
            'demasiado corta' => [['current_password' => self::TEMPORARY, 'password' => 'Corta1', 'password_confirmation' => 'Corta1'], 'password'],
            'sin números' => [['current_password' => self::TEMPORARY, 'password' => 'SoloLetrasAqui', 'password_confirmation' => 'SoloLetrasAqui'], 'password'],
            'sin letras' => [['current_password' => self::TEMPORARY, 'password' => '1234567890123', 'password_confirmation' => '1234567890123'], 'password'],
            'igual a la actual' => [['current_password' => self::TEMPORARY, 'password' => self::TEMPORARY, 'password_confirmation' => self::TEMPORARY], 'password'],
            'igual al usuario' => [['current_password' => self::TEMPORARY, 'password' => 'nuevo.usuario', 'password_confirmation' => 'nuevo.usuario'], 'password'],
            'vacía' => [['current_password' => self::TEMPORARY, 'password' => '', 'password_confirmation' => ''], 'password'],
        ];
    }

    /** @param  array<string, string>  $body */
    #[DataProvider('badChanges')]
    public function test_an_invalid_change_is_refused_and_changes_nothing(array $body, string $field): void
    {
        $user = $this->temporaryUser();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/password', $body)->assertStatus(422)->assertJsonValidationErrors($field);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->must_change_password);
        $this->assertTrue(Hash::check(self::TEMPORARY, $fresh->password));
    }

    public function test_guessing_the_current_password_is_rate_limited(): void
    {
        $user = $this->temporaryUser();
        $body = ['current_password' => 'Equivocada-1234', 'password' => self::NEW, 'password_confirmation' => self::NEW];

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/password', $body)->assertStatus(422);
        }
        // Con el límite alcanzado, ni siquiera la clave correcta se acepta hasta que pase el tiempo.
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/password', ['current_password' => self::TEMPORARY] + $body)->assertStatus(429);
    }

    public function test_the_change_is_audited_without_storing_any_password(): void
    {
        $user = $this->temporaryUser();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/password', ['current_password' => self::TEMPORARY, 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertOk();

        $this->assertDatabaseHas('audit_logs', ['event' => 'auth.password_changed', 'user_id' => $user->id]);
        $dump = json_encode(AuditLog::query()->get()->toArray());
        $this->assertStringNotContainsString(self::NEW, (string) $dump);
        $this->assertStringNotContainsString(self::TEMPORARY, (string) $dump);
    }

    public function test_the_password_change_requires_a_session(): void
    {
        $this->postJson('/api/v1/auth/password', ['current_password' => 'x', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertUnauthorized();
    }

    public function test_an_inactive_account_cannot_change_its_password(): void
    {
        $user = User::factory()->role(Role::VENDEDOR)->inactive()->create(['must_change_password' => true]);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/password', ['current_password' => 'x', 'password' => self::NEW, 'password_confirmation' => self::NEW])->assertForbidden();
    }

    public function test_a_password_reset_by_administration_forces_the_change_again(): void
    {
        $user = User::factory()->role(Role::VENDEDOR)->create(['must_change_password' => false, 'password_changed_at' => now()]);
        $company = Company::factory()->create();
        $body = ['username' => $user->username, 'name' => $user->name, 'password' => self::TEMPORARY, 'role_id' => $user->role_id, 'is_active' => true, 'company_ids' => [$company->id], 'branch_ids' => []];

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/users/{$user->id}", $body)->assertOk()->assertJsonPath('data.must_change_password', true);

        $fresh = $user->fresh();
        $this->assertTrue($fresh->must_change_password);
        $this->assertNull($fresh->password_changed_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'user.password_reset']);
    }

    public function test_editing_a_user_without_a_new_password_does_not_force_anything(): void
    {
        $user = User::factory()->role(Role::VENDEDOR)->create(['must_change_password' => false]);
        $body = ['username' => $user->username, 'name' => 'Nombre nuevo', 'role_id' => $user->role_id, 'is_active' => true, 'company_ids' => [], 'branch_ids' => []];

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/users/{$user->id}", $body)->assertOk()->assertJsonPath('data.must_change_password', false);
    }

    public function test_administration_setting_its_own_password_is_not_forced(): void
    {
        $body = ['username' => $this->admin->username, 'name' => $this->admin->name, 'password' => self::NEW, 'role_id' => $this->admin->role_id, 'is_active' => true, 'company_ids' => [], 'branch_ids' => []];

        $this->actingAs($this->admin, 'sanctum')->putJson("/api/v1/admin/users/{$this->admin->id}", $body)->assertOk()->assertJsonPath('data.must_change_password', false);
        $this->assertNotNull($this->admin->fresh()->password_changed_at);
    }

    public function test_the_flags_cannot_be_set_by_posting_them(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', $this->newUserBody('intento.manual') + ['must_change_password' => false, 'password_changed_at' => '2020-01-01'])->assertCreated();

        $user = User::where('username', 'intento.manual')->firstOrFail();
        $this->assertTrue($user->must_change_password, 'Una cuenta nueva siempre nace obligada a cambiar su clave.');
        $this->assertNull($user->password_changed_at);
    }
}
