<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Operator;
use App\Models\Permission;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\Reports\ReportAudit;
use App\Services\Reports\Semaphore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

/** Base de los reportes de evaluación: quién entra, qué puede elegir en los filtros, los semáforos y la auditoría. */
class ReportsFoundationTest extends TestCase
{
    use RefreshDatabase;

    private Company $alfa;

    private Company $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['name' => 'Alfa']);
        $this->beta = Company::factory()->create(['name' => 'Beta']);
    }

    private function userOf(string $role, array $companies = [], array $branches = []): User
    {
        $user = User::factory()->role($role)->create();
        $user->companies()->sync($companies);
        $user->branches()->sync($branches);

        return $user;
    }

    public function test_supervisors_managers_and_administrators_can_open_reports_but_sellers_cannot(): void
    {
        foreach ([Role::ADMINISTRADOR, Role::GERENTE_GENERAL, Role::SUPERVISOR_VENTAS] as $role) {
            $this->actingAs($this->userOf($role, [$this->alfa->id]), 'sanctum')->getJson('/api/v1/reports/context')->assertOk();
        }
        $this->actingAs($this->userOf(Role::VENDEDOR, [$this->alfa->id]), 'sanctum')->getJson('/api/v1/reports/context')->assertForbidden();
    }

    public function test_the_permission_is_granted_by_default_only_the_first_time(): void
    {
        $permission = Permission::where('slug', 'reports.view')->firstOrFail();
        $this->assertEqualsCanonicalizing([Role::ADMINISTRADOR, Role::GERENTE_GENERAL, Role::SUPERVISOR_VENTAS], $permission->roles->pluck('slug')->all());

        // Administración se lo quita al supervisor: volver a sembrar (p. ej. en un despliegue) no se lo devuelve.
        Role::where('slug', Role::SUPERVISOR_VENTAS)->firstOrFail()->permissions()->detach($permission->id);
        $this->seed(RolePermissionSeeder::class);

        $this->assertNotContains(Role::SUPERVISOR_VENTAS, $permission->fresh()->roles->pluck('slug')->all());
    }

    public function test_a_manager_picks_only_from_their_own_companies(): void
    {
        $branchAlfa = Branch::factory()->create(['company_id' => $this->alfa->id]);
        Branch::factory()->create(['company_id' => $this->beta->id]);
        Seller::create(['company_id' => $this->alfa->id, 'name' => 'Ana', 'normalized_name' => 'ana']);
        Seller::create(['company_id' => $this->beta->id, 'name' => 'Beto', 'normalized_name' => 'beto']);
        Operator::create(['company_id' => $this->alfa->id, 'name' => 'Omar', 'normalized_name' => 'omar']);
        Operator::create(['company_id' => $this->beta->id, 'name' => 'Olga', 'normalized_name' => 'olga']);

        $response = $this->actingAs($this->userOf(Role::GERENTE_GENERAL, [$this->alfa->id]), 'sanctum')->getJson('/api/v1/reports/context')->assertOk();

        $this->assertSame(['Alfa'], array_column($response->json('data.companies'), 'name'));
        $this->assertSame([$branchAlfa->id], array_column($response->json('data.branches'), 'id'));
        $this->assertSame(['Ana'], array_column($response->json('data.sellers'), 'name'));
        $this->assertSame(['Omar'], array_column($response->json('data.operators'), 'name'));
        $this->assertSame(['previous', 'last_year', 'custom'], $response->json('data.comparison_modes'));
    }

    public function test_a_supervisor_limited_to_some_branches_still_sees_every_seller_and_operator_of_the_company(): void
    {
        $mine = Branch::factory()->create(['company_id' => $this->alfa->id]);
        $other = Branch::factory()->create(['company_id' => $this->alfa->id]);
        Seller::create(['company_id' => $this->alfa->id, 'branch_id' => $mine->id, 'name' => 'Ana', 'normalized_name' => 'ana']);
        Seller::create(['company_id' => $this->alfa->id, 'branch_id' => $other->id, 'name' => 'Luis', 'normalized_name' => 'luis']);
        Operator::create(['company_id' => $this->alfa->id, 'name' => 'Omar', 'normalized_name' => 'omar']);

        $response = $this->actingAs($this->userOf(Role::SUPERVISOR_VENTAS, [$this->alfa->id], [$mine->id]), 'sanctum')->getJson('/api/v1/reports/context')->assertOk();

        $this->assertSame([$mine->id], array_column($response->json('data.branches'), 'id'), 'Las sucursales sí se limitan a las asignadas.');
        $this->assertEqualsCanonicalizing(['Ana', 'Luis'], array_column($response->json('data.sellers'), 'name'));
        $this->assertSame(['Omar'], array_column($response->json('data.operators'), 'name'));
    }

    public function test_a_user_without_companies_sees_nothing(): void
    {
        Seller::create(['company_id' => $this->alfa->id, 'name' => 'Ana', 'normalized_name' => 'ana']);

        $response = $this->actingAs($this->userOf(Role::GERENTE_GENERAL), 'sanctum')->getJson('/api/v1/reports/context')->assertOk();

        $this->assertSame([], $response->json('data.companies'));
        $this->assertSame([], $response->json('data.sellers'));
    }

    public function test_the_semaphore_follows_the_thresholds_in_both_directions(): void
    {
        $semaphore = new Semaphore;

        // Más es mejor (cumplimiento de meta: amarillo < 90, rojo < 70).
        $this->assertSame('good', $semaphore->level('goal_attainment', 100.0));
        $this->assertSame('good', $semaphore->level('goal_attainment', 90.0));
        $this->assertSame('warn', $semaphore->level('goal_attainment', 89.9));
        $this->assertSame('bad', $semaphore->level('goal_attainment', 69.9));
        // Menos es mejor (descuento: amarillo ≥ 5, rojo ≥ 10).
        $this->assertSame('good', $semaphore->level('discount_pct', 4.9));
        $this->assertSame('warn', $semaphore->level('discount_pct', 5.0));
        $this->assertSame('bad', $semaphore->level('discount_pct', 10.0));
        // Sin dato no se califica.
        $this->assertSame('none', $semaphore->level('goal_attainment', null));
        // Crecimiento negativo.
        $this->assertSame('bad', $semaphore->level('growth_pct', -12.0));
    }

    public function test_an_unknown_indicator_is_an_error_not_a_silent_green(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Semaphore)->level('inventado', 50.0);
    }

    public function test_every_threshold_is_well_formed(): void
    {
        foreach ((new Semaphore)->indicators() as $indicator) {
            $rule = config("reports.thresholds.$indicator");
            $this->assertIsBool($rule['higher_is_better'], $indicator);
            $this->assertTrue($rule['higher_is_better'] ? $rule['bad'] < $rule['warn'] : $rule['warn'] < $rule['bad'], "$indicator: el umbral rojo debe ser más severo que el amarillo.");
        }
    }

    public function test_reports_are_audited_with_the_filters_and_the_email_recipients(): void
    {
        $user = $this->userOf(Role::GERENTE_GENERAL, [$this->alfa->id]);
        $audit = app(ReportAudit::class);

        $audit->viewed($user, 'sellers', ['company_id' => $this->alfa->id, 'from' => '2026-10-01']);
        $audit->exported($user, 'sellers', 'xlsx', ['company_id' => $this->alfa->id]);
        $audit->emailed($user, 'sellers', 'pdf', ['jefe@ejemplo.com', 'duenio@ejemplo.com'], ['company_id' => $this->alfa->id]);

        $this->assertSame(['report.viewed', 'report.exported', 'report.emailed'], AuditLog::query()->where('user_id', $user->id)->where('event', 'like', 'report.%')->orderBy('id')->pluck('event')->all());
        $mail = AuditLog::where('event', 'report.emailed')->firstOrFail();
        $this->assertSame(['jefe@ejemplo.com', 'duenio@ejemplo.com'], $mail->new_values['recipients']);
        $this->assertSame($this->alfa->id, $mail->company_id);
    }
}
