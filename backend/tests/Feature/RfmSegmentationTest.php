<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditNote;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\InsightAlert;
use App\Models\InsightRun;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\RfmSegmentation;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use ReflectionMethod;
use Tests\TestCase;

class RfmSegmentationTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $branch;

    private User $admin;

    private User $viewer;

    private Seller $sellerOne;

    private Seller $sellerTwo;

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
        $this->sellerOne = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera']);
        $this->sellerTwo = Seller::create(['company_id' => $this->a->id, 'name' => 'Otro', 'normalized_name' => 'otro']);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->viewer->role_id, ['permissions' => ['insights.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function purchase(string $code, string $on, float $net = 100): void
    {
        $operator = Operator::firstOrCreate(['company_id' => $this->a->id, 'normalized_name' => 'caja'], ['name' => 'Caja']);
        $batch = ImportBatch::query()->value('id') ?? ImportBatch::factory()->create(['type' => 'sales', 'user_id' => $this->admin->id])->id;
        $docId = DB::table('sales_documents')->insertGetId([
            'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'F', 'number' => (string) ++$this->docs, 'issued_on' => $on, 'issued_at' => null, 'customer_code' => $code,
            'customer_name' => "Cliente $code", 'seller_id' => $this->sellerOne->id, 'operator_id' => $operator->id, 'import_batch_id' => $batch, 'lines_count' => 1, 'content_hash' => 'h'.$this->docs, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_lines')->insert([
            'sales_document_id' => $docId, 'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'issued_on' => $on, 'line_number' => 1, 'product_code' => '1', 'product_name' => 'P', 'category_name' => 'C',
            'units' => 1, 'unit_cost' => 1, 'line_cost_total' => 1, 'unit_price' => $net, 'normal_price' => $net, 'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** 25 clientes: el cliente i compra i días seguidos, 100 cada vez, y su última compra es más antigua cuanto menor es i. Más un cliente nuevo. */
    private function seedCustomers(): void
    {
        $today = Carbon::parse('2026-10-19');
        foreach (range(1, 25) as $i) {
            $last = $today->copy()->subDays((25 - $i) * 8);
            foreach (range(0, $i - 1) as $k) {
                $this->purchase(sprintf('C%02d', $i), $last->copy()->subDays($k)->toDateString());
            }
            Customer::create(['company_id' => $this->a->id, 'code' => sprintf('C%02d', $i), 'name' => sprintf('Cliente C%02d', $i), 'source' => 'sales', 'seller_id' => $i === 25 ? $this->sellerOne->id : $this->sellerTwo->id]);
        }
        $this->purchase('NUEVO', '2026-10-10');
        Customer::create(['company_id' => $this->a->id, 'code' => 'NUEVO', 'name' => 'Cliente NUEVO', 'source' => 'sales', 'seller_id' => $this->sellerTwo->id]);
    }

    private function company(): Company
    {
        return Company::query()->findOrFail($this->a->id);
    }

    public function test_segments_follow_the_fixed_rules_for_every_score_combination(): void
    {
        $segment = new ReflectionMethod(RfmSegmentation::class, 'segment');
        $rfm = app(RfmSegmentation::class);
        $of = fn (int $r, float $fm, bool $new = false) => $segment->invoke($rfm, $r, $fm, $new);

        $this->assertSame(['new', 'champion', 'champion', 'loyal', 'loyal', 'promising', 'promising', 'at_risk', 'at_risk', 'dormant', 'lost'], [
            $of(5, 1.0, true), $of(5, 5.0), $of(4, 4.0), $of(4, 3.5), $of(3, 3.0), $of(5, 2.5), $of(3, 1.0), $of(2, 3.0), $of(1, 5.0), $of(2, 2.5), $of(1, 2.5),
        ]);
        // No queda ninguna combinación sin segmento.
        foreach (range(1, 5) as $r) {
            foreach ([1.0, 1.5, 2.0, 2.5, 3.0, 3.5, 4.0, 4.5, 5.0] as $fm) {
                $this->assertArrayHasKey($of($r, $fm), RfmSegmentation::SEGMENTS);
            }
        }
    }

    public function test_quintiles_give_ties_the_same_score_and_split_evenly(): void
    {
        $quintile = new ReflectionMethod(RfmSegmentation::class, 'quintile');
        $rfm = app(RfmSegmentation::class);
        $all = range(1, 25);

        $this->assertSame([1, 1, 2, 3, 4, 5], array_map(fn (int $v) => $quintile->invoke($rfm, $v, $all, true), [1, 5, 6, 11, 16, 25]));
        $this->assertSame([5, 1], [$quintile->invoke($rfm, 1, $all, false), $quintile->invoke($rfm, 25, $all, false)]);      // recencia: menos días es mejor
        $this->assertSame([1, 1], [$quintile->invoke($rfm, 1, [1, 1, 1, 1, 1], true), $quintile->invoke($rfm, 1, [1, 1, 1, 1, 1], true)]);   // todos empatados
    }

    public function test_it_segments_every_customer_and_records_the_calculation(): void
    {
        $this->seedCustomers();
        CreditNote::create(['company_id' => $this->a->id, 'series' => 'NC', 'number' => '1', 'issued_on' => '2026-09-01', 'customer_code' => 'C25', 'customer_name' => 'Cliente C25', 'subtotal' => 500, 'tax_total' => 0, 'total' => 500, 'created_at' => now()]);

        $run = app(RfmSegmentation::class)->compute($this->company(), $this->admin);

        $this->assertSame(InsightRun::OK, $run->status);
        $this->assertSame('2025-10-21', $run->period_from->toDateString());
        $this->assertSame('2026-10-20', $run->period_to->toDateString());
        $this->assertSame(26, $run->summary['customers']);
        $this->assertSame(12, $run->parameters['rfm.period_months']);
        $s = DB::table('rfm_scores');
        $this->assertSame(26, $s->count());

        $top = $s->clone()->where('customer_code', 'C25')->first();
        // Las devoluciones restan del valor: con 2.000 netos queda por debajo del cliente C24 (2.400), así que su valor es 4/5.
        $this->assertSame([5, 5, 4, 'champion', 1, 25, 2500.0, 500.0, 2000.0], [(int) $top->r, (int) $top->f, (int) $top->m, $top->segment, (int) $top->recency_days, (int) $top->frequency, (float) $top->gross, (float) $top->returns, (float) $top->monetary]);
        $this->assertSame(['lost', 1, 1, 1], [DB::table('rfm_scores')->where('customer_code', 'C01')->value('segment'), ...array_map(fn ($c) => (int) DB::table('rfm_scores')->where('customer_code', 'C01')->value($c), ['r', 'f', 'm'])]);
        $this->assertSame('new', DB::table('rfm_scores')->where('customer_code', 'NUEVO')->value('segment'));
        $this->assertSame(array_sum($run->summary['segments']), 26);

        // Los clientes «En riesgo» también son recomendaciones del panel.
        $this->assertSame($run->summary['segments']['at_risk'], InsightAlert::withoutGlobalScopes()->where('type', 'rfm_at_risk')->count());
    }

    public function test_recalculating_replaces_the_scores_but_keeps_every_run(): void
    {
        $this->seedCustomers();
        app(RfmSegmentation::class)->compute($this->company());
        $second = app(RfmSegmentation::class)->compute($this->company());

        $this->assertSame(26, DB::table('rfm_scores')->count());
        $this->assertSame([$second->id], DB::table('rfm_scores')->pluck('insight_run_id')->unique()->values()->all());
        $this->assertSame(2, InsightRun::where('analysis', 'rfm')->count());
    }

    public function test_it_does_not_calculate_without_enough_history_or_customers(): void
    {
        // Compañía sin ventas: historial insuficiente.
        $none = app(RfmSegmentation::class)->compute(Company::query()->findOrFail($this->b->id));
        $this->assertSame([InsightRun::INSUFFICIENT, 0], [$none->status, DB::table('rfm_scores')->count()]);
        $this->assertStringContainsString('Historial insuficiente', (string) $none->note);

        // Historial suficiente pero pocos clientes.
        $this->purchase('X1', '2026-01-05');
        $this->purchase('X2', '2026-10-15');
        $few = app(RfmSegmentation::class)->compute($this->company());
        $this->assertSame(InsightRun::INSUFFICIENT, $few->status);
        $this->assertStringContainsString('se necesitan al menos 20', (string) $few->note);
        $this->assertSame(0, DB::table('rfm_scores')->count());
    }

    public function test_the_api_lists_filters_and_explains(): void
    {
        $this->seedCustomers();
        app(RfmSegmentation::class)->compute($this->company());
        $url = '/api/v1/insights/rfm?company_id='.$this->a->id;

        $r = $this->actingAs($this->viewer, 'sanctum')->getJson($url)->assertOk();
        $this->assertSame('C25', $r->json('data.0.code'));                                           // ordenado por valor
        $this->assertSame(26, array_sum($r->json('meta.counts')));
        $this->assertSame('ok', $r->json('meta.run.status'));
        $this->assertSame(7, count($r->json('meta.segments')));
        $this->assertStringContainsString('Compró por última vez hace 1 días (recencia 5/5), tuvo 25 días con compra (frecuencia 5/5)', $r->json('data.0.explanation'));
        $this->assertStringContainsString('Campeones', $r->json('data.0.explanation'));

        $this->assertSame(['NUEVO'], array_column($this->getJson($url.'&segment=new')->json('data'), 'code'));
        $this->assertSame(['C01'], array_column($this->getJson($url.'&q=c01')->json('data'), 'code'));
        $this->getJson($url.'&segment=inventado')->assertUnprocessable();
        $this->getJson('/api/v1/insights/rfm')->assertUnprocessable();

        $one = $this->getJson('/api/v1/insights/rfm/customer?company_id='.$this->a->id.'&code=C25')->assertOk();
        $this->assertSame('champion', $one->json('data.segment'));
        $this->assertNull($this->getJson('/api/v1/insights/rfm/customer?company_id='.$this->a->id.'&code=NADIE')->json('data'));
    }

    public function test_a_seller_only_sees_the_segments_of_their_customers(): void
    {
        $this->seedCustomers();
        app(RfmSegmentation::class)->compute($this->company());
        $vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $vendedor->companies()->attach($this->a->id);
        $this->sellerOne->update(['user_id' => $vendedor->id]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$vendedor->role_id, ['permissions' => ['insights.view']])->assertOk();

        $r = $this->actingAs($vendedor, 'sanctum')->getJson('/api/v1/insights/rfm?company_id='.$this->a->id)->assertOk();
        $this->assertSame(['C25'], array_column($r->json('data'), 'code'));
        $this->assertSame(1, array_sum($r->json('meta.counts')));
        $this->assertNull($this->getJson('/api/v1/insights/rfm/customer?company_id='.$this->a->id.'&code=C01')->json('data'));
    }

    public function test_recalculating_needs_the_manage_permission_and_export_is_audited(): void
    {
        $this->seedCustomers();
        $this->actingAs($this->viewer, 'sanctum')->postJson('/api/v1/insights/rfm/recalculate', ['company_id' => $this->a->id])->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/insights/rfm/recalculate', ['company_id' => $this->a->id])->assertOk()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.customers', 26);

        DB::table('rfm_scores')->where('customer_code', 'C01')->update(['customer_name' => '=HYPERLINK("x")']);
        $csv = $this->actingAs($this->viewer, 'sanctum')->get('/api/v1/insights/rfm/export?company_id='.$this->a->id)->assertOk()->streamedContent();
        $this->assertStringContainsString('Explicación', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('Campeones', $csv);
        $this->assertDatabaseHas('audit_logs', ['event' => 'insights.rfm_exported']);
    }
}
