<?php

declare(strict_types=1);

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
use App\Services\CrossSellAnalysis;
use App\Services\InsightParameters;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrossSellTest extends TestCase
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
        // Umbrales de prueba: el mínimo de 1.000 facturas es el de producción.
        app(InsightParameters::class)->update($this->admin, ['cross_sell.min_documents' => 100, 'cross_sell.min_support_customers' => 5]);
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
            'sales_document_id' => $docId, 'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'issued_on' => $on, 'line_number' => 1, 'product_code' => 'P-'.$category, 'product_name' => "Producto $category",
            'category_name' => $category, 'units' => 1, 'unit_cost' => 1, 'line_cost_total' => 1, 'unit_price' => $net, 'normal_price' => $net, 'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 40 clientes: 20 compran Filtros y Frenos, 10 solo Filtros, 10 solo Llantas. 120+ facturas repartidas en 5 meses. */
    private function seedBaskets(): void
    {
        $dates = ['2026-06-10', '2026-07-10', '2026-08-10', '2026-09-10', '2026-10-10'];
        foreach (range(1, 40) as $i) {
            $code = sprintf('C%02d', $i);
            $categories = $i <= 20 ? ['Filtros', 'Frenos'] : ($i <= 30 ? ['Filtros'] : ['Llantas']);
            foreach ($categories as $k => $category) {
                $this->buy($code, $category, $dates[$i % 5]);
                $this->buy($code, $category, $dates[($i + $k + 1) % 5]);
            }
            $this->buy($code, $categories[0], $dates[($i + 2) % 5]);
            Customer::create(['company_id' => $this->a->id, 'code' => $code, 'name' => "Cliente $code", 'source' => 'sales', 'seller_id' => $i === 21 ? $this->mine->id : $this->other->id]);
        }
    }

    private function compute(): InsightRun
    {
        return app(CrossSellAnalysis::class)->compute(Company::query()->findOrFail($this->a->id), $this->admin);
    }

    public function test_rules_have_confidence_and_lift_and_only_strong_ones_are_kept(): void
    {
        $this->seedBaskets();
        $run = $this->compute();

        $this->assertSame(InsightRun::OK, $run->status);
        $this->assertSame([40, 2, 10], [$run->summary['customers'], $run->summary['rules'], $run->summary['opportunities']]);
        $rules = DB::table('cross_sell_rules')->get()->keyBy(fn ($r) => "{$r->antecedent}→{$r->consequent}");
        $this->assertEqualsCanonicalizing(['Filtros→Frenos', 'Frenos→Filtros'], $rules->keys()->all());

        $fr = $rules['Frenos→Filtros'];                                   // los 20 clientes de Frenos compran Filtros; 30 de 40 compran Filtros
        $this->assertEquals([20, 30, 20, 40, 1.0, 1.3333], [$fr->antecedent_customers, $fr->consequent_customers, $fr->both_customers, $fr->total_customers, (float) $fr->confidence, (float) $fr->lift]);
        $ff = $rules['Filtros→Frenos'];                                   // 20 de 30 (66,7 %); 20 de 40 compran Frenos → elevación 1,33
        $this->assertEquals([30, 20, 20, 0.6667, 1.3333], [$ff->antecedent_customers, $ff->consequent_customers, $ff->both_customers, (float) $ff->confidence, (float) $ff->lift]);
    }

    public function test_opportunities_are_customers_with_the_antecedent_and_without_the_consequent(): void
    {
        $this->seedBaskets();
        $this->compute();

        $codes = DB::table('cross_sell_opportunities as o')->join('cross_sell_rules as r', 'r.id', '=', 'o.rule_id')->select('o.customer_code', 'r.consequent', 'r.antecedent')->get();
        $this->assertCount(10, $codes);
        $this->assertSame(['Frenos'], $codes->pluck('consequent')->unique()->values()->all());
        $this->assertSame(['Filtros'], $codes->pluck('antecedent')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(range(21, 30), $codes->map(fn ($c) => (int) substr($c->customer_code, 1))->all());   // solo Filtros

        // Cada oportunidad es también una recomendación del panel, con su explicación.
        $alerts = InsightAlert::withoutGlobalScopes()->where('type', 'cross_sell')->get();
        $this->assertCount(10, $alerts);
        $this->assertSame(['medium', 'open'], [$alerts->first()->severity, $alerts->first()->status]);
        $this->assertStringContainsString('Compra «Filtros» y todavía no «Frenos»', $alerts->first()->explanation);
        $this->compute();
        $this->assertSame(10, InsightAlert::withoutGlobalScopes()->where('type', 'cross_sell')->count());      // no se duplican
    }

    public function test_it_applies_the_thresholds_and_the_per_customer_cap(): void
    {
        $this->seedBaskets();
        app(InsightParameters::class)->update($this->admin, ['cross_sell.min_confidence_pct' => 80]);
        $this->assertSame(1, $this->compute()->summary['rules']);                  // solo Frenos→Filtros (100 %); Filtros→Frenos queda en 66,7 %
        $this->assertSame(0, $this->compute()->summary['opportunities']);          // todos los de Frenos ya compran Filtros

        app(InsightParameters::class)->update($this->admin, ['cross_sell.min_confidence_pct' => 30, 'cross_sell.min_support_customers' => 21]);
        $this->assertSame(0, $this->compute()->summary['rules']);                  // el respaldo (20 clientes) no alcanza
    }

    public function test_it_does_not_run_without_enough_history_invoices_or_customers(): void
    {
        $this->buy('C1', 'Filtros', '2026-10-01');
        $short = $this->compute();
        $this->assertSame([InsightRun::INSUFFICIENT, 0], [$short->status, DB::table('cross_sell_rules')->count()]);
        $this->assertStringContainsString('insuficientes', (string) $short->note);

        app(InsightParameters::class)->update($this->admin, ['cross_sell.min_documents' => 100]);
        $this->seedBaskets();
        app(InsightParameters::class)->update($this->admin, ['cross_sell.min_support_customers' => 100]);
        $few = $this->compute();
        $this->assertSame(InsightRun::INSUFFICIENT, $few->status);
        $this->assertStringContainsString('se necesitan al menos 100', (string) $few->note);
    }

    public function test_recalculating_replaces_the_previous_rules(): void
    {
        $this->seedBaskets();
        $this->compute();
        $second = $this->compute();

        $this->assertSame(2, DB::table('cross_sell_rules')->count());
        $this->assertSame([$second->id], DB::table('cross_sell_rules')->pluck('insight_run_id')->unique()->values()->all());
        $this->assertSame(2, InsightRun::where('analysis', 'cross_sell')->count());
    }

    public function test_the_api_explains_each_rule_and_opportunity(): void
    {
        $this->seedBaskets();
        $this->compute();
        $url = '/api/v1/insights/cross-sell?company_id='.$this->a->id;

        $rules = $this->actingAs($this->viewer, 'sanctum')->getJson($url)->assertOk();
        $this->assertSame(['Frenos', 'Filtros', 100.0], [$rules->json('data.0.antecedent'), $rules->json('data.0.consequent'), (float) $rules->json('data.0.confidence_pct')]);
        $this->assertStringContainsString('20 de 20 clientes que compran «Frenos» (100,0 %) también compran «Filtros»', $rules->json('data.0.explanation'));
        $this->assertSame(['ok', 'categoría'], [$rules->json('meta.run.status'), $rules->json('meta.run.level')]);

        $opp = $this->getJson('/api/v1/insights/cross-sell/opportunities?company_id='.$this->a->id)->assertOk();
        $this->assertSame(10, $opp->json('meta.total'));
        $first = $opp->json('data.0');
        $this->assertSame(['Frenos', 'Filtros', 66.7, 20, 1.33], [$first['suggestion'], $first['because'], (float) $first['confidence_pct'], $first['support'], (float) $first['lift']]);
        $this->assertStringContainsString('Compra «Filtros» y todavía no «Frenos»', $first['explanation']);
        $this->assertCount(10, $this->getJson('/api/v1/insights/cross-sell/opportunities?company_id='.$this->a->id.'&target=Frenos')->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/insights/cross-sell/opportunities?company_id='.$this->a->id.'&target=Llantas')->json('data'));
        $this->assertSame(['C21'], array_column($this->getJson('/api/v1/insights/cross-sell/opportunities?company_id='.$this->a->id.'&q=c21')->json('data'), 'code'));
        $this->getJson('/api/v1/insights/cross-sell/opportunities')->assertUnprocessable();

        $one = $this->getJson('/api/v1/insights/cross-sell/customer?company_id='.$this->a->id.'&code=C21')->assertOk();
        $this->assertCount(1, $one->json('data'));
        $this->assertCount(0, $this->getJson('/api/v1/insights/cross-sell/customer?company_id='.$this->a->id.'&code=C05')->json('data'));
    }

    public function test_a_seller_only_sees_the_opportunities_of_their_customers_and_only_managers_recalculate(): void
    {
        $this->seedBaskets();
        $this->compute();
        $vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $vendedor->companies()->attach($this->a->id);
        $this->mine->update(['user_id' => $vendedor->id]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$vendedor->role_id, ['permissions' => ['insights.view']])->assertOk();

        $r = $this->actingAs($vendedor, 'sanctum')->getJson('/api/v1/insights/cross-sell/opportunities?company_id='.$this->a->id)->assertOk();
        $this->assertSame(['C21'], array_column($r->json('data'), 'code'));        // el único cliente asignado a su vendedor con oportunidad
        $this->assertSame(1, $r->json('meta.total'));

        $this->actingAs($this->viewer, 'sanctum')->postJson('/api/v1/insights/cross-sell/recalculate', ['company_id' => $this->a->id])->assertForbidden();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/insights/cross-sell/recalculate', ['company_id' => $this->a->id])->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.opportunities', 10);
    }
}
