<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionIsCurrent;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** «Época de sesión»: cerrar sesión, cambiar o restablecer la clave y desactivar invalidan todas las sesiones anteriores, incluso las que reviven. */
class SessionEpochTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Clave-vigente-123';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->withHeader('Origin', 'http://localhost:5173')->withHeader('Referer', 'http://localhost:5173/');   // la interfaz: petición con sesión
        $this->user = User::factory()->role(Role::ADMINISTRADOR)->create(['username' => 'epoca', 'password' => self::PASSWORD]);
    }

    /** Inicia sesión como lo haría un equipo y devuelve lo que quedó guardado en esa sesión. @return array<string, mixed> */
    private function signIn(string $username = 'epoca', string $password = self::PASSWORD): array
    {
        $this->flushSession();
        $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => $password])->assertOk();

        return session()->all();
    }

    /** Hace que las siguientes peticiones lleguen con esa sesión. En las pruebas los guards conservan al usuario de la petición anterior; en producción cada petición es nueva. @param  array<string, mixed>  $payload */
    private function present(array $payload): void
    {
        $this->flushSession();
        $this->withSession($payload);
        Auth::forgetGuards();
    }

    /** Presenta de nuevo una sesión guardada, como si el navegador de ese equipo siguiera usándola. @param  array<string, mixed>  $payload */
    private function replay(array $payload): TestResponse
    {
        $this->present($payload);

        return $this->getJson('/api/v1/auth/me');
    }

    public function test_a_new_session_remembers_the_epoch_it_was_opened_with(): void
    {
        $session = $this->signIn();

        $this->assertSame(0, $session['session_epoch']);
        $this->replay($session)->assertOk();
    }

    public function test_after_signing_out_the_old_session_is_dead_even_if_a_request_in_flight_brings_it_back(): void
    {
        $session = $this->signIn();

        $this->present($session);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        // El navegador (o una petición que ya iba en camino) vuelve a presentar la sesión de antes: sigue con su identidad, pero con la época vieja.
        $this->replay($session)->assertUnauthorized()->assertJsonPath('code', EnsureSessionIsCurrent::CODE);
        $this->assertSame(1, $this->user->fresh()->session_epoch);
    }

    public function test_signing_out_on_one_device_closes_the_others_too(): void
    {
        $laptop = $this->signIn();
        $phone = $this->signIn();
        $this->replay($phone)->assertOk();
        $this->replay($laptop)->assertOk();

        $this->present($laptop);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->replay($phone)->assertUnauthorized();
    }

    public function test_signing_in_again_after_signing_out_starts_a_valid_session_with_the_new_epoch(): void
    {
        $old = $this->signIn();
        $this->present($old);
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $fresh = $this->signIn();

        $this->assertSame(1, $fresh['session_epoch']);
        $this->replay($fresh)->assertOk();
        $this->replay($old)->assertUnauthorized();
    }

    public function test_changing_the_password_keeps_this_session_and_closes_every_other(): void
    {
        $other = $this->signIn();
        $mine = $this->signIn();
        $this->present($mine);

        $response = $this->postJson('/api/v1/auth/password', ['current_password' => self::PASSWORD, 'password' => 'Definitiva-67890', 'password_confirmation' => 'Definitiva-67890'])->assertOk();
        $afterChange = session()->all();

        $this->assertSame(1, $afterChange['session_epoch'], 'La sesión que cambió la clave se conserva con la época nueva.');
        $this->replay($afterChange)->assertOk();
        $this->replay($other)->assertUnauthorized();
        $this->assertNotNull($response->json('data.id'));
    }

    public function test_an_administrator_resetting_someone_elses_password_closes_that_persons_sessions_but_not_their_own(): void
    {
        $other = User::factory()->role(Role::VENDEDOR)->create(['username' => 'vendedor.x', 'password' => self::PASSWORD]);
        $theirs = $this->signIn('vendedor.x');
        $mine = $this->signIn();
        $this->present($mine);

        $this->putJson("/api/v1/admin/users/{$other->id}", ['username' => 'vendedor.x', 'name' => $other->name, 'password' => 'Temporal-12345', 'role_id' => $other->role_id, 'is_active' => true, 'company_ids' => [], 'branch_ids' => []])->assertOk();

        $mineAfter = session()->all();

        $this->replay($theirs)->assertUnauthorized();
        $this->replay($mineAfter)->assertOk();
        $this->assertSame(1, $other->fresh()->session_epoch);
        $this->assertSame(0, $this->user->fresh()->session_epoch);
    }

    public function test_setting_your_own_password_from_the_users_screen_does_not_throw_you_out(): void
    {
        $other = $this->signIn();
        $mine = $this->signIn();
        $this->present($mine);

        $this->putJson("/api/v1/admin/users/{$this->user->id}", ['username' => 'epoca', 'name' => $this->user->name, 'password' => 'Definitiva-67890', 'role_id' => $this->user->role_id, 'is_active' => true, 'company_ids' => [], 'branch_ids' => []])->assertOk();
        $afterChange = session()->all();

        $this->replay($afterChange)->assertOk();
        $this->replay($other)->assertUnauthorized();
    }

    public function test_deactivating_an_account_closes_its_sessions_for_good_even_if_it_is_reactivated(): void
    {
        $other = User::factory()->role(Role::VENDEDOR)->create(['username' => 'vendedor.x', 'password' => self::PASSWORD]);
        $theirs = $this->signIn('vendedor.x');
        $admin = $this->signIn();
        $edit = fn (bool $active) => $this->putJson("/api/v1/admin/users/{$other->id}", ['username' => 'vendedor.x', 'name' => $other->name, 'role_id' => $other->role_id, 'is_active' => $active, 'company_ids' => [], 'branch_ids' => []])->assertOk();

        $this->present($admin);
        $edit(false);
        $edit(true);

        $this->replay($theirs)->assertUnauthorized();
    }

    public function test_editing_a_user_without_touching_the_password_or_the_status_leaves_sessions_alone(): void
    {
        $other = User::factory()->role(Role::VENDEDOR)->create(['username' => 'vendedor.x', 'password' => self::PASSWORD]);
        $theirs = $this->signIn('vendedor.x');
        $admin = $this->signIn();
        $this->present($admin);

        $this->putJson("/api/v1/admin/users/{$other->id}", ['username' => 'vendedor.x', 'name' => 'Nombre nuevo', 'role_id' => $other->role_id, 'is_active' => true, 'company_ids' => [], 'branch_ids' => []])->assertOk();

        $this->replay($theirs)->assertOk();
    }

    public function test_a_session_opened_before_this_control_existed_works_until_the_epoch_moves(): void
    {
        $legacy = $this->signIn();
        unset($legacy['session_epoch']);

        $this->replay($legacy)->assertOk();

        $this->user->increment('session_epoch');
        $this->replay($legacy)->assertUnauthorized();
    }

    public function test_a_wrong_password_still_does_not_open_a_session(): void
    {
        $this->flushSession();

        $this->postJson('/api/v1/auth/login', ['username' => 'epoca', 'password' => 'incorrecta'])->assertStatus(422);

        $this->assertArrayNotHasKey('session_epoch', session()->all());
    }
}
