<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\InsightRun;
use App\Models\Operator;
use App\Models\Product;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\SeasonalityAnalysis;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SeasonalityTest extends TestCase
{
    use RefreshDatabase;

    /** Factor estacional por mes del año (enero a diciembre) de la compañía; el promedio es 1,0. */
    private const PATTERN = [0.8, 0.8, 0.9, 1.0, 1.0, 1.1, 1.1, 1.0, 0.9, 1.0, 1.2, 1.2];

    private Company $a;

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
        $this->viewer->companies()->attach($this->a->id);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        Product::create(['company_id' => $this->a->id, 'code' => 'PA', 'name' => 'Producto A', 'level2' => 'Línea A']);
        Product::create(['company_id' => $this->a->id, 'code' => 'PB', 'name' => 'Producto B', 'level2' => 'Línea B']);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->viewer->role_id, ['permissions' => ['insights.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(string $on, string $product, float $net): void
    {
        $seller = Seller::firstOrCreate(['company_id' => $this->a->id, 'normalized_name' => 'vera'], ['name' => 'Vera']);
        $operator = Operator::firstOrCreate(['company_id' => $this->a->id, 'normalized_name' => 'caja'], ['name' => 'Caja']);
        $batch = ImportBatch::query()->value('id') ?? ImportBatch::factory()->create(['type' => 'sales', 'user_id' => $this->admin->id])->id;
        $docId = DB::table('sales_documents')->insertGetId([
            'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'F', 'number' => (string) ++$this->docs, 'issued_on' => $on, 'issued_at' => null, 'customer_code' => 'C1',
            'customer_name' => 'Cliente', 'seller_id' => $seller->id, 'operator_id' => $operator->id, 'import_batch_id' => $batch, 'lines_count' => 1, 'content_hash' => 'h'.$this->docs, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_lines')->insert([
            'sales_document_id' => $docId, 'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'issued_on' => $on, 'line_number' => 1, 'product_code' => $product, 'product_name' => $product, 'category_name' => 'C',
            'units' => 1, 'unit_cost' => 1, 'line_cost_total' => 1, 'unit_price' => $net, 'normal_price' => $net, 'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /**
     * De 2024-01 a 2026-07 (31 meses; el primero y el último quedan incompletos): la línea A sigue el patrón estacional con 1 % de
     * crecimiento mensual y la línea B vende siempre lo mismo.
     */
    private function seedYears(): void
    {
        $this->sale('2024-01-02', 'PB', 1);                                      // marca el inicio del historial (mes incompleto)
        $this->sale('2026-07-20', 'PB', 1);                                      // y el final
        $t = 0;
        for ($m = Carbon::parse('2024-02-10'); $m <= Carbon::parse('2026-06-10'); $m = $m->copy()->addMonth(), $t++) {
            $this->sale($m->toDateString(), 'PA', 1000 * self::PATTERN[$m->month - 1] * (1 + 0.01 * $t));
            $this->sale($m->copy()->addDay()->toDateString(), 'PB', 500);
        }
    }

    private function compute(): InsightRun
    {
        return app(SeasonalityAnalysis::class)->compute(Company::query()->findOrFail($this->a->id), $this->admin);
    }

    public function test_it_recovers_the_seasonal_pattern_despite_a_growth_trend(): void
    {
        $this->seedYears();
        $run = $this->compute();

        $this->assertSame(InsightRun::OK, $run->status);
        $this->assertSame([29, '2024-02', '2026-06'], [$run->summary['months'], $run->summary['first_month'], $run->summary['last_month']]);
        $line = collect($run->summary['lines'])->firstWhere('name', 'Línea A');
        $expected = array_map(fn (float $f) => $f / (array_sum(self::PATTERN) / 12) * 100, self::PATTERN);
        foreach ($line['indices'] as $i => $index) {
            $this->assertEqualsWithDelta($expected[$i], $index, 6.0, 'mes '.($i + 1));       // dentro de 6 puntos del patrón real, aun con tendencia
        }
        $this->assertEqualsWithDelta(100.0, array_sum($line['indices']) / 12, 0.2);          // el promedio de los doce índices es 100
        $this->assertContains($line['peak'], [11, 12]);
        $this->assertContains($line['trough'], [1, 2]);
        $this->assertTrue($line['marked']);
        $this->assertSame([2, 3, 3, 3, 3, 3, 2, 2, 2, 2, 2, 2], $line['observations']);       // febrero a junio aparecen tres veces; los demás, dos
    }

    public function test_a_flat_line_has_no_marked_seasonality(): void
    {
        $this->seedYears();
        $b = collect($this->compute()->summary['lines'])->firstWhere('name', 'Línea B');

        $this->assertFalse($b['marked']);
        $this->assertLessThan(5.0, $b['amplitude']);
        foreach ($b['indices'] as $index) {
            $this->assertEqualsWithDelta(100.0, $index, 3.0);
        }
    }

    public function test_it_compares_each_month_with_the_same_month_of_the_previous_year(): void
    {
        $this->seedYears();
        $yoy = collect($this->compute()->summary['yoy'])->keyBy('month');

        $this->assertCount(12, $yoy);
        $june = $yoy['2026-06'];                                                            // 2026-06 contra 2025-06
        $this->assertEqualsWithDelta(7.4, $june['change_pct'], 1.0);                        // la línea A crece ≈ 12 % anual y la B no crece: el total, ≈ 7 %
        $this->assertGreaterThan((float) $june['previous'], (float) $june['value']);
    }

    public function test_it_is_not_calculated_without_the_minimum_history(): void
    {
        foreach (range(1, 14) as $i) {                                                      // 14 meses de ventas
            $this->sale(Carbon::parse('2025-05-10')->addMonths($i)->toDateString(), 'PA', 100);
        }
        $run = $this->compute();

        $this->assertSame(InsightRun::INSUFFICIENT, $run->status);
        $this->assertStringContainsString('Historial insuficiente', (string) $run->note);
        $this->assertNull($run->summary);
    }

    public function test_the_api_serves_the_latest_run_and_only_managers_recalculate(): void
    {
        $this->seedYears();
        $url = '/api/v1/insights/seasonality?company_id='.$this->a->id;
        $this->assertNull($this->actingAs($this->viewer, 'sanctum')->getJson($url)->assertOk()->json('data'));

        $this->actingAs($this->viewer, 'sanctum')->postJson('/api/v1/insights/seasonality/recalculate', ['company_id' => $this->a->id])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/insights/seasonality/recalculate', ['company_id' => $this->a->id])->assertOk()->assertJsonPath('data.status', 'ok');

        $r = $this->actingAs($this->viewer, 'sanctum')->getJson($url)->assertOk();
        $this->assertSame(['ok', 29, 12], [$r->json('data.status'), $r->json('data.summary.months'), count($r->json('data.summary.company.indices'))]);
        $this->assertSame(24, $r->json('data.parameters')['seasonality.min_history_months']);
    }
}
