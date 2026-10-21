<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\InsightAlert;
use App\Models\InsightRun;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\AnomalyAnalysis;
use App\Services\InactivityRiskAnalysis;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CustomerAlertsTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $branch;

    private User $admin;

    private User $viewer;

    private Seller $mine;

    private Seller $other;

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
        $this->mine = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera']);
        $this->other = Seller::create(['company_id' => $this->a->id, 'name' => 'Otro', 'normalized_name' => 'otro']);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->viewer->role_id, ['permissions' => ['insights.view']])->assertOk();
        $this->buy('BASE', 'General', '2026-01-05');          // da 9 meses de historial a la compañía
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function buy(string $code, string $category, string $on, float $net = 100): void
    {
        $operator = Operator::firstOrCreate(['company_id' => $this->a->id, 'normalized_name' => 'caja'], ['name' => 'Caja']);
        $batch = ImportBatch::query()->value('id') ?? ImportBatch::factory()->create(['type' => 'sales', 'user_id' => $this->admin->id])->id;
        $docId = DB::table('sales_documents')->insertGetId([
            'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'F', 'number' => (string) ++$this->docs, 'issued_on' => $on, 'issued_at' => null, 'customer_code' => $code,
            'customer_name' => "Cliente $code", 'seller_id' => $this->mine->id, 'operator_id' => $operator->id, 'import_batch_id' => $batch, 'lines_count' => 1, 'content_hash' => 'h'.$this->docs, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_lines')->insert([
            'sales_document_id' => $docId, 'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'issued_on' => $on, 'line_number' => 1, 'product_code' => 'P-'.$category, 'product_name' => $category,
            'category_name' => $category, 'units' => 1, 'unit_cost' => 1, 'line_cost_total' => 1, 'unit_price' => $net, 'normal_price' => $net, 'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** @param  list<string>  $dates */
    private function buyOn(string $code, array $dates): void
    {
        foreach ($dates as $d) {
            $this->buy($code, 'General', $d);
        }
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->a->id);
    }

    private function alerts(string $type): Collection
    {
        return InsightAlert::withoutGlobalScopes()->where('type', $type)->get()->keyBy('customer_code');
    }

    private function every(string $from, int $days, int $times): array
    {
        return array_map(fn (int $i) => Carbon::parse($from)->addDays($i * $days)->toDateString(), range(0, $times - 1));
    }

    public function test_an_early_warning_compares_the_gap_with_the_customers_own_rhythm(): void
    {
        $this->buyOn('R1', $this->every('2026-08-01', 7, 7));                       // cada 7 días, la última el 12-sep (38 días atrás)
        $this->buyOn('R2', ['2026-05-01', '2026-05-31', '2026-06-30', '2026-07-30', '2026-08-29']);   // cada 30; lleva 52 (< 60)
        $this->buyOn('R3', $this->every('2026-01-05', 10, 14));                      // ya perdido (más de 90 días)
        $this->buyOn('R4', ['2026-08-01', '2026-08-10']);                            // pocas compras para medir su ritmo
        $this->buyOn('R5', $this->every('2026-10-06', 7, 3));                        // al día (compró hoy)

        $run = app(InactivityRiskAnalysis::class)->compute($this->company(), $this->admin);

        $this->assertSame(InsightRun::OK, $run->status);
        $alerts = $this->alerts(InsightAlert::INACTIVITY_RISK);
        $this->assertSame(['R1'], $alerts->keys()->all());
        $r1 = $alerts['R1'];
        $this->assertSame(['high', 7, 38, 7, 'open'], [$r1->severity, (int) $r1->facts['typical_interval_days'], $r1->facts['days_since_last'], $r1->facts['purchases'], $r1->status]);
        $this->assertStringContainsString('Suele comprar cada 7 días', $r1->explanation);
        $this->assertStringContainsString('lleva 38 días sin comprar', $r1->explanation);
        $this->assertSame(1, $run->summary['alerts']);
    }

    public function test_alerts_resolve_themselves_when_the_condition_stops_and_closed_ones_are_not_reopened(): void
    {
        $this->buyOn('R1', $this->every('2026-08-01', 7, 7));
        $analysis = app(InactivityRiskAnalysis::class);
        $analysis->compute($this->company());
        $analysis->compute($this->company());                                          // repetir no duplica
        $this->assertSame(1, InsightAlert::withoutGlobalScopes()->count());

        InsightAlert::withoutGlobalScopes()->update(['status' => InsightAlert::CLOSED, 'closed_at' => now(), 'close_reason' => 'contacted']);
        $analysis->compute($this->company());
        $this->assertSame([InsightAlert::CLOSED], InsightAlert::withoutGlobalScopes()->pluck('status')->all());      // sigue cerrada

        InsightAlert::withoutGlobalScopes()->update(['status' => InsightAlert::OPEN, 'closed_at' => null]);
        $this->buyOn('R1', ['2026-10-19']);                                              // vuelve a comprar
        $run = $analysis->compute($this->company());
        $this->assertSame(InsightAlert::RESOLVED, InsightAlert::withoutGlobalScopes()->value('status'));
        $this->assertSame(1, $run->summary['sync']['resolved']);
    }

    public function test_sales_changes_are_measured_against_the_customers_own_average(): void
    {
        $month = fn (string $code, string $m, float $net, int $days = 1) => array_map(fn ($d) => $this->buy($code, 'General', "$m-".sprintf('%02d', 5 + $d), $net / $days), range(0, $days - 1));
        foreach (['D1', 'D2', 'D3', 'D5'] as $code) {
            $month($code, '2026-06', 1000);
            $month($code, '2026-07', 1000);
            $month($code, '2026-08', 1000);
        }
        $month('D1', '2026-09', 400);          // −60 %
        $month('D2', '2026-09', 2500);         // +150 %
        $month('D3', '2026-09', 1000);         // sin cambio
        $month('D4', '2026-06', 1000);         // solo 2 días de compra en la referencia
        $month('D4', '2026-07', 1000);
        $this->buy('D1', 'General', '2026-10-15');        // el mes de la última venta (octubre) está incompleto y no se evalúa

        $run = app(AnomalyAnalysis::class)->compute($this->company(), $this->admin);

        $this->assertSame(['2026-09', InsightRun::OK], [$run->summary['evaluated_month'], $run->status]);
        $drops = $this->alerts(InsightAlert::SALES_DROP);
        $this->assertEqualsCanonicalizing(['D1', 'D5'], $drops->keys()->all());
        $this->assertSame(['medium', -60.0, 'sales_drop:D1:2026-09'], [$drops['D1']->severity, (float) $drops['D1']->facts['change_pct'], $drops['D1']->dedupe_key]);
        $this->assertSame(['high', -100.0], [$drops['D5']->severity, (float) $drops['D5']->facts['change_pct']]);
        $this->assertStringContainsString('un 60 % menos que su promedio de los 3 meses anteriores', $drops['D1']->explanation);
        $rises = $this->alerts(InsightAlert::SALES_RISE);
        $this->assertSame(['D2'], $rises->keys()->all());
        $this->assertSame(150.0, (float) $rises['D2']->facts['change_pct']);
    }

    public function test_a_category_the_customer_used_to_buy_and_dropped_is_flagged_while_they_keep_buying(): void
    {
        foreach (['2026-01-20', '2026-03-10', '2026-05-10'] as $d) {
            $this->buy('A1', 'Filtros', $d);                                                // hábito: 3 meses distintos antes de la ventana reciente
        }
        foreach (['2026-07-05', '2026-08-05', '2026-09-05'] as $d) {
            $this->buy('A1', 'Frenos', $d);                                                 // sigue comprando, pero otra categoría
        }
        foreach (['2026-01-20', '2026-03-10', '2026-05-10'] as $d) {
            $this->buy('A2', 'Filtros', $d);                                                // dejó de comprar todo: es inactividad, no línea abandonada
        }
        foreach (['2026-01-20', '2026-03-10', '2026-05-10', '2026-08-05'] as $d) {
            $this->buy('A3', 'Filtros', $d);                                                // sigue comprando Filtros
        }
        $this->buy('A3', 'Frenos', '2026-09-05');

        app(AnomalyAnalysis::class)->compute($this->company());

        $abandoned = InsightAlert::withoutGlobalScopes()->where('type', InsightAlert::ABANDONED_LINE)->get();
        $this->assertSame(['A1'], $abandoned->pluck('customer_code')->all());
        $a = $abandoned->first();
        $this->assertSame(['Filtros', 3, '2026-05-10', 'low'], [$a->facts['category'], $a->facts['months_with_purchases'], $a->facts['last_purchase'], $a->severity]);
        $this->assertStringContainsString('Compraba «Filtros» en 3 de los 6 meses anteriores', $a->explanation);
    }

    public function test_nothing_is_calculated_without_enough_history(): void
    {
        DB::table('sales_lines')->delete();
        DB::table('sales_documents')->delete();
        $this->buy('X', 'General', '2026-10-01');

        foreach ([[InactivityRiskAnalysis::class, 'inactivity'], [AnomalyAnalysis::class, 'anomalies']] as [$class, $analysis]) {
            $run = app($class)->compute($this->company());
            $this->assertSame([InsightRun::INSUFFICIENT, $analysis], [$run->status, $run->analysis]);
        }
        $this->assertSame(0, InsightAlert::withoutGlobalScopes()->count());
    }

    public function test_the_api_lists_filters_and_scopes_alerts(): void
    {
        $this->buyOn('R1', $this->every('2026-08-01', 7, 7));
        Customer::create(['company_id' => $this->a->id, 'code' => 'R1', 'name' => 'Cliente R1', 'source' => 'sales', 'seller_id' => $this->other->id]);
        foreach (['D1' => 400, 'D5' => 0] as $code => $sep) {
            foreach (['2026-06', '2026-07', '2026-08'] as $m) {
                $this->buy($code, 'General', "$m-10", 1000);
            }
            if ($sep > 0) {
                $this->buy($code, 'General', '2026-09-10', $sep);
            }
            Customer::create(['company_id' => $this->a->id, 'code' => $code, 'name' => "Cliente $code", 'source' => 'sales', 'seller_id' => $code === 'D1' ? $this->mine->id : $this->other->id]);
        }
        $this->buy('D1', 'General', '2026-10-15');
        app(InactivityRiskAnalysis::class)->compute($this->company());
        app(AnomalyAnalysis::class)->compute($this->company());
        $url = '/api/v1/insights/alerts?company_id='.$this->a->id;

        $r = $this->actingAs($this->viewer, 'sanctum')->getJson($url)->assertOk();
        $this->assertSame(['inactivity_risk' => 2, 'sales_drop' => 2, 'sales_rise' => 0, 'abandoned_line' => 0, 'cross_sell' => 0, 'rfm_at_risk' => 0], $r->json('meta.counts'));
        $this->assertSame('high', $r->json('data.0.severity'));                                   // lo más grave primero
        $this->assertSame(['sales_drop'], array_unique(array_column($this->getJson($url.'&type=sales_drop')->json('data'), 'type')));
        $this->assertSame(['D1'], array_column($this->getJson($url.'&type=sales_drop&severity=medium')->json('data'), 'code'));
        $this->assertSame(['R1'], array_column($this->getJson($url.'&q=r1')->json('data'), 'code'));      // D5 también está en riesgo de inactividad: compra cada ~30 días y lleva 71
        $this->getJson($url.'&type=inventado')->assertUnprocessable();
        $this->getJson('/api/v1/insights/alerts')->assertUnprocessable();
        $this->assertCount(0, $this->getJson($url.'&status=closed')->json('data'));

        $one = $this->getJson('/api/v1/insights/alerts/customer?company_id='.$this->a->id.'&code=D1')->assertOk();
        $this->assertSame(['sales_drop'], array_column($one->json('data'), 'type'));
        $this->assertArrayHasKey('change_pct', $one->json('data.0.facts'));

        $vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $vendedor->companies()->attach($this->a->id);
        $this->mine->update(['user_id' => $vendedor->id]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$vendedor->role_id, ['permissions' => ['insights.view']])->assertOk();
        $this->assertSame(['D1'], array_column($this->actingAs($vendedor, 'sanctum')->getJson($url)->json('data'), 'code'));
        $this->assertCount(0, $this->getJson('/api/v1/insights/alerts/customer?company_id='.$this->a->id.'&code=R1')->json('data'));
        $this->actingAs(User::factory()->role(Role::GERENTE_GENERAL)->create(), 'sanctum')->getJson($url)->assertForbidden();
    }
}
