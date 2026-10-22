<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\ImportBatch;
use App\Models\InsightRun;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\CohortAnalysis;
use App\Services\ConcentrationAnalysis;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConcentrationAndCohortsTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $branch;

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
        $this->viewer->companies()->attach([$this->a->id, $this->b->id]);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->viewer->role_id, ['permissions' => ['insights.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(string $code, string $on, float $net, string $product = 'P1'): void
    {
        $seller = Seller::firstOrCreate(['company_id' => $this->a->id, 'normalized_name' => 'vera'], ['name' => 'Vera']);
        $operator = Operator::firstOrCreate(['company_id' => $this->a->id, 'normalized_name' => 'caja'], ['name' => 'Caja']);
        $batch = ImportBatch::query()->value('id') ?? ImportBatch::factory()->create(['type' => 'sales', 'user_id' => $this->admin->id])->id;
        $docId = DB::table('sales_documents')->insertGetId([
            'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'F', 'number' => (string) ++$this->docs, 'issued_on' => $on, 'issued_at' => null, 'customer_code' => $code,
            'customer_name' => "Cliente $code", 'seller_id' => $seller->id, 'operator_id' => $operator->id, 'import_batch_id' => $batch, 'lines_count' => 1, 'content_hash' => 'h'.$this->docs, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_lines')->insert([
            'sales_document_id' => $docId, 'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'issued_on' => $on, 'line_number' => 1, 'product_code' => $product, 'product_name' => "Producto $product", 'category_name' => 'C',
            'units' => 1, 'unit_cost' => 1, 'line_cost_total' => 1, 'unit_price' => $net, 'normal_price' => $net, 'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function concentrationData(): void
    {
        $this->sale('C1', '2026-05-05', 8000, 'P1');
        $this->sale('C2', '2026-06-05', 1000, 'P2');
        $this->sale('C3', '2026-07-05', 500, 'P2');
        $this->sale('C4', '2026-08-05', 300, 'P2');
        $this->sale('C5', '2026-10-15', 200, 'P2');
    }

    public function test_concentration_measures_pareto_top_shares_and_dependency(): void
    {
        $this->concentrationData();
        $run = app(ConcentrationAnalysis::class)->compute(Company::query()->findOrFail($this->a->id), $this->admin);

        $this->assertSame(InsightRun::OK, $run->status);
        $c = $run->summary['customers'];
        $this->assertEquals([5, '10000.0000', 80.0, 100.0, 1, 6538], [$c['count'], $c['total'], $c['top1_pct'], $c['top10_pct'], $c['n80'], $c['hhi']]);
        $this->assertSame(['C1', 'C2', 'C3', 'C4', 'C5'], array_column($c['top'], 'code'));
        $this->assertEquals([80.0, 90.0, 95.0, 98.0, 100.0], array_column($c['top'], 'cumulative_pct'));
        $this->assertSame(['C1'], array_column($c['alerts'], 'code'));                            // ≥ 15 % de la venta
        $this->assertSame(15, $run->summary['alert_pct']);
        $this->assertSame([20, 100], [$c['curve'][3]['entities_pct'], end($c['curve'])['entities_pct']]);
        $this->assertEquals([20, 80.0, 100.0], [$c['curve'][3]['entities_pct'], $c['curve'][3]['sales_pct'], end($c['curve'])['sales_pct']]);   // 20 % de los clientes = 80 % de la venta

        $p = $run->summary['products'];
        $this->assertEquals([2, 80.0, 1], [$p['count'], $p['top1_pct'], $p['n80']]);
        $this->assertSame('Producto P1', $p['top'][0]['name']);
        $this->assertSame([], $p['alerts']);                                                      // los productos no llevan aviso de dependencia
    }

    public function test_returns_reduce_a_customers_weight(): void
    {
        $this->concentrationData();
        CreditNote::create(['company_id' => $this->a->id, 'series' => 'NC', 'number' => '1', 'issued_on' => '2026-09-01', 'customer_code' => 'C1', 'customer_name' => 'Cliente C1', 'subtotal' => 1000, 'tax_total' => 0, 'total' => 1000, 'created_at' => now()]);

        $c = app(ConcentrationAnalysis::class)->compute(Company::query()->findOrFail($this->a->id))->summary['customers'];

        $this->assertEquals(['9000.0000', 77.78, 2], [$c['total'], $c['top1_pct'], $c['n80']]);   // 7.000 de 9.000: ya no alcanza el 80 % solo
    }

    public function test_concentration_is_not_calculated_without_enough_history_or_sales(): void
    {
        $this->sale('C1', '2026-10-01', 100);
        $short = app(ConcentrationAnalysis::class)->compute(Company::query()->findOrFail($this->a->id));
        $this->assertSame([InsightRun::INSUFFICIENT, null], [$short->status, $short->summary]);
        $this->assertStringContainsString('Historial insuficiente', (string) $short->note);

        $none = app(ConcentrationAnalysis::class)->compute(Company::query()->findOrFail($this->b->id));
        $this->assertSame(InsightRun::INSUFFICIENT, $none->status);
    }

    private function cohortData(): void
    {
        $buy = fn (string $code, array $months) => array_map(fn (string $m) => $this->sale($code, "$m-10", 100), $months);
        $buy('A', ['2026-01', '2026-02']);
        $buy('B', ['2026-02', '2026-03', '2026-05']);
        $buy('C', ['2026-02', '2026-04']);
        $buy('D', ['2026-03']);
        $buy('E', ['2026-03', '2026-04']);
        $buy('F', ['2026-08']);
    }

    public function test_cohorts_measure_monthly_retention_with_only_observable_months(): void
    {
        $this->cohortData();
        $run = app(CohortAnalysis::class)->compute(Company::query()->findOrFail($this->a->id), $this->admin);

        $this->assertSame(InsightRun::OK, $run->status);
        $cohorts = collect($run->summary['cohorts'])->keyBy('month');
        $this->assertSame(['2026-01', '2026-08'], [$run->summary['first_month'], $run->summary['last_month']]);
        $this->assertTrue($cohorts['2026-01']['prior']);                                          // clientes previos al historial
        $this->assertFalse($cohorts['2026-02']['prior']);
        $this->assertSame(2, $cohorts['2026-02']['customers']);
        $this->assertEquals([100.0, 50.0, 50.0, 50.0, 0.0, 0.0, null], array_slice($cohorts['2026-02']['retention'], 0, 7));
        $this->assertEquals([100.0, 50.0, 0.0, 0.0, 0.0, null], array_slice($cohorts['2026-03']['retention'], 0, 6));
        $this->assertEquals(array_fill(0, 13, null), $cohorts['2026-08']['retention']);              // el mes de la última venta está incompleto
        $this->assertEquals([100.0, 50.0, 25.0, 25.0, 0.0, 0.0, null], array_slice($run->summary['average'], 0, 7));   // sin la cohorte previa
    }

    public function test_the_api_returns_the_latest_run_and_hides_it_from_sellers(): void
    {
        $this->concentrationData();
        $url = '/api/v1/insights/concentration?company_id='.$this->a->id;
        $this->assertNull($this->actingAs($this->viewer, 'sanctum')->getJson($url)->assertOk()->json('data'));    // todavía no se calcula

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/insights/concentration/recalculate', ['company_id' => $this->a->id])->assertOk()->assertJsonPath('data.status', 'ok');
        $r = $this->actingAs($this->viewer, 'sanctum')->getJson($url)->assertOk();
        $this->assertSame([5, 'ok'], [$r->json('data.summary.customers.count'), $r->json('data.status')]);
        $this->assertSame(12, $r->json('data.parameters')['concentration.period_months']);

        $this->actingAs($this->viewer, 'sanctum')->postJson('/api/v1/insights/cohorts/recalculate', ['company_id' => $this->a->id])->assertForbidden();
        $this->postJson('/api/v1/insights/otro/recalculate', ['company_id' => $this->a->id])->assertNotFound();
        $this->getJson('/api/v1/insights/cohorts')->assertUnprocessable();

        $vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $vendedor->companies()->attach($this->a->id);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$vendedor->role_id, ['permissions' => ['insights.view']])->assertOk();
        $this->actingAs($vendedor, 'sanctum')->getJson($url)->assertForbidden();
        $this->getJson('/api/v1/insights/cohorts?company_id='.$this->a->id)->assertForbidden();
    }

    public function test_the_refresh_command_calculates_and_logs_every_analysis(): void
    {
        $this->cohortData();
        $this->concentrationData();

        $this->artisan('insights:refresh', ['--company' => $this->a->id])->assertSuccessful();

        $this->assertEqualsCanonicalizing(['rfm', 'concentration', 'cohorts', 'cross_sell', 'inactivity', 'anomalies', 'seasonality'], InsightRun::where('company_id', $this->a->id)->pluck('analysis')->all());
        $this->assertSame('ok', InsightRun::where(['company_id' => $this->a->id, 'analysis' => 'cohorts'])->value('status'));
        $this->assertSame('insufficient', InsightRun::where(['company_id' => $this->a->id, 'analysis' => 'rfm'])->value('status'));   // menos de 20 clientes
    }
}
