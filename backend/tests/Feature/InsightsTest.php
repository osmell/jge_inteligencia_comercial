<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\InsightRun;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\InsightAvailability;
use App\Services\InsightParameters;
use App\Services\InsightRunLogger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class InsightsTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Company $c;

    private User $admin;

    private User $viewer;

    private int $docs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->viewer = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $this->a = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        $this->b = Company::factory()->create(['code' => 'B', 'name' => 'Beta']);
        $this->c = Company::factory()->create(['code' => 'C', 'name' => 'Gamma']);
        $this->viewer->companies()->attach([$this->a->id, $this->b->id, $this->c->id]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->viewer->role_id, ['permissions' => ['insights.view']])->assertOk();

        $this->sale($this->a, '2026-01-05');
        $this->sale($this->a, '2026-10-15');
        $this->sale($this->c, '2026-05-01');
        $this->sale($this->c, '2026-06-01');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(Company $company, string $on): void
    {
        $branch = Branch::where('company_id', $company->id)->first() ?? Branch::factory()->create(['company_id' => $company->id]);
        $seller = Seller::firstOrCreate(['company_id' => $company->id, 'normalized_name' => 'vera'], ['name' => 'Vera']);
        $operator = Operator::firstOrCreate(['company_id' => $company->id, 'normalized_name' => 'caja'], ['name' => 'Caja']);
        $batch = ImportBatch::query()->value('id') ?? ImportBatch::factory()->create(['type' => 'sales', 'user_id' => $this->admin->id])->id;
        DB::table('sales_documents')->insert([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'series' => 'F', 'number' => (string) ++$this->docs, 'issued_on' => $on, 'issued_at' => null, 'customer_code' => 'C1',
            'customer_name' => 'Cliente', 'seller_id' => $seller->id, 'operator_id' => $operator->id, 'import_batch_id' => $batch, 'lines_count' => 1, 'content_hash' => 'h'.$this->docs, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function overview(?User $as = null): array
    {
        return collect($this->actingAs($as ?? $this->viewer, 'sanctum')->getJson('/api/v1/insights/status')->assertOk()->json('data'))->keyBy('company')->all();
    }

    private function analysis(array $company, string $key): array
    {
        return collect($company['analyses'])->firstWhere('key', $key);
    }

    public function test_an_analysis_is_available_only_with_enough_history(): void
    {
        $status = $this->overview();

        $alfa = $status['Alfa'];
        $this->assertSame([9, 2, '2026-01-05', '2026-10-15', false], [$alfa['history_months'], $alfa['documents'], $alfa['first_sale'], $alfa['last_sale'], $alfa['stale']]);
        $this->assertTrue($this->analysis($alfa, 'rfm')['available']);
        $this->assertTrue($this->analysis($alfa, 'cohorts')['available']);
        $this->assertTrue($this->analysis($alfa, 'anomalies')['available']);
        $seasonality = $this->analysis($alfa, 'seasonality');
        $this->assertSame([false, 'Historial insuficiente: tiene 9 de 24 meses de historial.'], [$seasonality['available'], $seasonality['reason']]);
        $crossSell = $this->analysis($alfa, 'cross_sell');
        $this->assertFalse($crossSell['available']);
        $this->assertStringContainsString('tiene 2 de 1.000 facturas', $crossSell['reason']);

        $beta = $status['Beta'];                                               // sin ventas
        $this->assertSame([0, 0, null], [$beta['history_months'], $beta['documents'], $beta['first_sale']]);
        $this->assertFalse($this->analysis($beta, 'rfm')['available']);
        $this->assertStringContainsString('tiene 0 de 6 meses', $this->analysis($beta, 'rfm')['reason']);

        $gamma = $status['Gamma'];                                            // 1 mes de historial y datos viejos
        $this->assertSame([1, true], [$gamma['history_months'], $gamma['stale']]);
        $this->assertFalse($this->analysis($gamma, 'rfm')['available']);
    }

    public function test_changing_a_parameter_changes_what_is_available(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/insights/parameters', ['values' => ['rfm.min_history_months' => 10]])->assertOk();

        $alfa = $this->overview()['Alfa'];
        $this->assertFalse($this->analysis($alfa, 'rfm')['available']);
        $this->assertSame(10, $this->analysis($alfa, 'rfm')['required_months']);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/insights/parameters', ['values' => ['rfm.min_history_months' => 6]])->assertOk();
        $this->assertTrue($this->analysis($this->overview()['Alfa'], 'rfm')['available']);
    }

    public function test_parameters_are_listed_with_defaults_validated_and_audited(): void
    {
        $list = collect($this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/insights/parameters')->assertOk()->json('data'))->keyBy('key');
        $this->assertSame([12, 12, false, 'RFM'], [$list['rfm.period_months']['value'], $list['rfm.period_months']['default'], $list['rfm.period_months']['customized'], $list['rfm.period_months']['group']]);
        $this->assertSame([40, 100], [$list['anomaly.drop_pct']['value'], $list['anomaly.rise_pct']['value']]);
        $this->assertSame(24, $list['seasonality.min_history_months']['value']);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/insights/parameters', ['values' => ['anomaly.drop_pct' => 50, 'rfm.period_months' => 12]])->assertOk();
        $log = DB::table('audit_logs')->where('event', 'insights.parameters_updated')->sole();
        $this->assertSame(['anomaly.drop_pct' => 40], json_decode($log->old_values, true));          // solo lo que cambió
        $this->assertSame(['anomaly.drop_pct' => 50], json_decode($log->new_values, true));
        $this->assertTrue(collect($this->getJson('/api/v1/insights/parameters')->json('data'))->firstWhere('key', 'anomaly.drop_pct')['customized']);

        $this->putJson('/api/v1/insights/parameters', ['values' => ['anomaly.drop_pct' => 3]])->assertUnprocessable()->assertJsonValidationErrors(['values.anomaly.drop_pct']);
        $this->putJson('/api/v1/insights/parameters', ['values' => ['anomaly.drop_pct' => 'mucho']])->assertUnprocessable();
        $this->putJson('/api/v1/insights/parameters', ['values' => ['inventado' => 1]])->assertUnprocessable();
        $this->putJson('/api/v1/insights/parameters', [])->assertUnprocessable();
        $this->assertSame(50, app(InsightParameters::class)->get('anomaly.drop_pct'));
    }

    public function test_only_insights_managers_change_parameters_and_viewing_needs_the_permission(): void
    {
        $this->actingAs($this->viewer, 'sanctum')->putJson('/api/v1/insights/parameters', ['values' => ['anomaly.drop_pct' => 50]])->assertForbidden();
        $this->actingAs(User::factory()->role(Role::VENDEDOR)->create(), 'sanctum');
        $this->getJson('/api/v1/insights/status')->assertForbidden();
        $this->getJson('/api/v1/insights/parameters')->assertForbidden();
        $this->getJson('/api/v1/insights/runs')->assertForbidden();
    }

    public function test_a_user_only_sees_the_companies_in_their_scope(): void
    {
        $limited = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $limited->companies()->attach($this->a->id);

        $this->assertSame(['Alfa'], array_keys($this->overview($limited)));
    }

    public function test_every_calculation_is_logged_with_its_period_and_parameters_and_cannot_change(): void
    {
        $run = app(InsightRunLogger::class)->record($this->a->id, 'rfm', InsightRun::OK, Carbon::parse('2025-10-01'), Carbon::parse('2026-09-30'), ['customers' => 150], 'Cálculo de prueba', $this->admin);
        app(InsightRunLogger::class)->record($this->b->id, 'cohorts', InsightRun::INSUFFICIENT, null, null, null, 'Sin historial');

        $this->assertSame(12, $run->parameters['rfm.period_months']);
        $rows = $this->actingAs($this->viewer, 'sanctum')->getJson('/api/v1/insights/runs')->assertOk()->json('data');
        $this->assertCount(2, $rows);
        $this->assertSame(['rfm', 'ok', '2025-10-01', '2026-09-30', 150], [$rows[1]['analysis'], $rows[1]['status'], $rows[1]['period_from'], $rows[1]['period_to'], $rows[1]['summary']['customers']]);
        $this->assertSame(['cohorts'], array_column($this->getJson('/api/v1/insights/runs?company_id='.$this->b->id)->json('data'), 'analysis'));
        $this->assertSame(['rfm'], array_column($this->getJson('/api/v1/insights/runs?analysis=rfm')->json('data'), 'analysis'));
        $this->getJson('/api/v1/insights/runs?analysis=inventado')->assertUnprocessable();

        // El último cálculo aparece en el estado.
        $this->assertSame('ok', $this->analysis($this->overview()['Alfa'], 'rfm')['last_run']['status']);
        $this->assertTrue(app(InsightAvailability::class)->isAvailable($this->a->id, 'rfm'));

        $this->expectException(LogicException::class);
        $run->update(['note' => 'x']);
    }
}
