<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\PaymentPromise;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $branch;

    private User $admin;

    private User $supervisor;

    private User $vera;

    private User $beto;

    private Seller $sVera;

    private Seller $sBeto;

    private int $docs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 10:00:00');                           // miércoles
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $this->vera = User::factory()->role(Role::VENDEDOR)->create();
        $this->beto = User::factory()->role(Role::VENDEDOR)->create();
        $this->a = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        $this->b = Company::factory()->create(['code' => 'B', 'name' => 'Beta']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        foreach ([$this->supervisor, $this->vera, $this->beto] as $u) {
            $u->companies()->attach($this->a->id);
        }
        $this->sVera = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vera->id, 'supervisor_id' => $this->supervisor->id]);
        $this->sBeto = Seller::create(['company_id' => $this->a->id, 'name' => 'Beto', 'normalized_name' => 'beto', 'user_id' => $this->beto->id]);       // sin supervisor
        $perms = ['sales.view', 'credit.view', 'customers.view', 'interactions.manage'];
        foreach ([Role::SUPERVISOR_VENTAS, Role::VENDEDOR] as $slug) {
            $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.Role::where('slug', $slug)->value('id'), ['permissions' => $perms])->assertOk();
        }
        Auth::forgetGuards();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(string $on, float $net, Seller $seller, string $customer): void
    {
        $operator = Operator::firstOrCreate(['company_id' => $this->a->id, 'normalized_name' => 'caja'], ['name' => 'Caja']);
        $batch = ImportBatch::query()->value('id') ?? ImportBatch::factory()->create(['type' => 'sales', 'user_id' => $this->admin->id])->id;
        $docId = DB::table('sales_documents')->insertGetId([
            'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'F', 'number' => (string) ++$this->docs, 'issued_on' => $on, 'issued_at' => null, 'customer_code' => $customer, 'customer_name' => "Cliente $customer",
            'seller_id' => $seller->id, 'operator_id' => $operator->id, 'import_batch_id' => $batch, 'lines_count' => 1, 'content_hash' => 'h'.$this->docs, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_lines')->insert([
            'sales_document_id' => $docId, 'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'issued_on' => $on, 'line_number' => 1, 'product_code' => 'P1', 'product_name' => 'Producto P1', 'category_name' => 'C',
            'units' => 1, 'unit_cost' => 1, 'line_cost_total' => 1, 'unit_price' => $net, 'normal_price' => $net, 'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedBusiness(): void
    {
        Customer::create(['company_id' => $this->a->id, 'code' => 'CV', 'name' => 'Cliente CV', 'source' => 'sales', 'seller_id' => $this->sVera->id]);
        Customer::create(['company_id' => $this->a->id, 'code' => 'CB', 'name' => 'Cliente CB', 'source' => 'sales', 'seller_id' => $this->sBeto->id]);
        // Octubre (1 al 21): Vera 1000 + 500, Beto 2000. Septiembre (1 al 21): Vera 800, Beto 1200. Octubre 2025: Vera 600.
        $this->sale('2026-10-05', 1000, $this->sVera, 'CV');
        $this->sale('2026-10-20', 500, $this->sVera, 'CV');
        $this->sale('2026-10-10', 2000, $this->sBeto, 'CB');
        $this->sale('2026-09-10', 800, $this->sVera, 'CV');
        $this->sale('2026-09-15', 1200, $this->sBeto, 'CB');
        $this->sale('2026-09-28', 999, $this->sVera, 'CV');                            // fuera del mismo tramo del mes anterior
        $this->sale('2025-10-08', 600, $this->sVera, 'CV');
        $operator = Operator::first();
        foreach ([['1', '2026-09-11', 1000, $this->sVera, 'CV'], ['2', '2026-07-12', 500, $this->sBeto, 'CB']] as [$n, $issued, $total, $seller, $code]) {
            $i = CreditInvoice::create(['company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'A', 'number' => $n, 'issued_on' => $issued, 'customer_code' => $code, 'customer_name' => "Cliente $code", 'seller_id' => $seller->id, 'operator_id' => $operator->id, 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'total' => $total, 'content_hash' => $n]);
            CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => $i->id, 'type' => 'invoice', 'amount' => $total, 'occurred_on' => $issued, 'created_at' => now()]);
        }
        $cv = Customer::where('code', 'CV')->value('id');
        $cb = Customer::where('code', 'CB')->value('id');
        $quote = fn (int $n, int $customer, Seller $seller, string $until) => Quotation::create(['company_id' => $this->a->id, 'customer_id' => $customer, 'seller_id' => $seller->id, 'sequence' => $n, 'number' => sprintf('COT-%06d', $n), 'status' => 'sent', 'amount' => 300, 'valid_until' => $until, 'issued_at' => '2026-10-12 09:00:00']);
        $quote(1, $cv, $this->sVera, '2026-10-23');
        $quote(2, $cb, $this->sBeto, '2026-12-01');
        DB::table('seller_goals')->insert([['seller_id' => $this->sVera->id, 'month' => '2026-10-01', 'amount' => 3000, 'days_off' => 0, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()], ['seller_id' => $this->sBeto->id, 'month' => '2026-10-01', 'amount' => 4000, 'days_off' => 0, 'source' => 'manual', 'created_at' => now(), 'updated_at' => now()]]);
    }

    private function section(User $user, string $name, string $query = '')
    {
        return $this->actingAs($user, 'sanctum')->getJson("/api/v1/dashboard/$name$query");
    }

    public function test_each_role_gets_its_profile_and_only_the_sections_its_permissions_allow(): void
    {
        $this->assertSame(['company', null], [$this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/dashboard')->assertOk()->json('data.profile'), $this->getJson('/api/v1/dashboard')->json('data.linked_sellers')]);
        $this->assertSame(['sales', 'goals', 'rankings', 'companies', 'credit', 'quotations', 'followup', 'inventory', 'insights'], $this->getJson('/api/v1/dashboard')->json('data.sections'));

        $team = $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/v1/dashboard')->assertOk();
        $this->assertSame(['team', 1, ['Vera']], [$team->json('data.profile'), $team->json('data.linked_sellers'), $team->json('data.team')]);
        $this->assertSame(['sales', 'goals', 'rankings', 'credit', 'quotations', 'followup'], $team->json('data.sections'));          // sin inventario ni recomendaciones, ni comparativo
        $this->assertSame('seller', $this->actingAs($this->vera, 'sanctum')->getJson('/api/v1/dashboard')->json('data.profile'));

        $this->section($this->vera, 'inventory')->assertNotFound();
        $this->section($this->admin, 'inventado')->assertNotFound();
        Auth::forgetGuards();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_sales_compare_with_the_same_stretch_of_last_month_and_last_year(): void
    {
        $this->seedBusiness();
        $r = $this->section($this->admin, 'sales')->assertOk();

        $this->assertEquals([3500, 3], [$r->json('data.current.net_total'), $r->json('data.current.documents')]);
        $this->assertSame(['2026-09-01', '2026-09-21'], [$r->json('data.previous.from'), $r->json('data.previous.to')]);
        $this->assertEquals([2000, 75.0], [$r->json('data.previous.net_total'), $r->json('data.previous.change_pct')]);            // 3500 contra 800 + 1200
        $this->assertSame(['2025-10-01', '2025-10-21'], [$r->json('data.year_ago.from'), $r->json('data.year_ago.to')]);
        $this->assertEquals(600, $r->json('data.year_ago.net_total'));
        $this->assertSame(['2026-10-05', '2026-10-10', '2026-10-20'], array_column($r->json('data.daily'), 'date'));
        $this->assertSame(['2026-09-10', '2026-09-15'], array_column($r->json('data.daily_previous'), 'date'));
        $this->assertSame(['2026-09', '2026-10'], array_column($r->json('data.monthly'), 'month'));       // últimos 12 meses con ventas (octubre de 2025 queda fuera)

        $this->section($this->admin, 'sales', '?period=year')->assertJsonPath('data.year_ago', null);
        $prev = $this->section($this->admin, 'sales', '?period=prev_month')->assertOk();
        $this->assertEquals([800 + 1200 + 999, '2026-09-01', '2026-09-30'], [$prev->json('data.current.net_total'), $prev->json('data.period.from'), $prev->json('data.period.to')]);
    }

    public function test_the_seller_sees_only_their_own_figures_and_the_supervisor_only_their_team(): void
    {
        $this->seedBusiness();

        $this->assertEquals(1500, $this->section($this->vera, 'sales')->json('data.current.net_total'));
        $this->assertEquals(2000, $this->section($this->beto, 'sales')->json('data.current.net_total'));
        $this->assertEquals(1500, $this->section($this->supervisor, 'sales')->json('data.current.net_total'));       // solo Vera: Beto no tiene supervisor
        $this->assertEquals(3500, $this->section($this->admin, 'sales')->json('data.current.net_total'));

        $this->assertSame(['Cliente CV'], array_column($this->section($this->vera, 'rankings')->json('data.customers'), 'label'));
        $rank = $this->section($this->vera, 'rankings');
        $this->assertArrayNotHasKey('sellers', $rank->json('data'));                                                      // el vendedor no ve el ranking de vendedores
        $this->assertSame(['Vera'], array_column($this->section($this->supervisor, 'rankings')->json('data.sellers'), 'label'));
        $this->assertSame(['Beto', 'Vera'], array_column($this->section($this->admin, 'rankings')->json('data.sellers'), 'label'));
    }

    public function test_goals_show_progress_and_projection_for_the_scope_of_each_role(): void
    {
        $this->seedBusiness();
        $admin = $this->section($this->admin, 'goals')->assertOk();
        $this->assertEquals([7000, 3500, 3500, 50.0, 2], [$admin->json('data.totals.goal'), $admin->json('data.totals.actual'), $admin->json('data.totals.remaining'), $admin->json('data.totals.compliance_pct'), $admin->json('data.totals.sellers_with_goal')]);
        $this->assertSame(['2026-10', 'current'], [$admin->json('data.month'), $admin->json('data.state')]);
        $this->assertNotNull($admin->json('data.totals.projected'));
        $this->assertEquals([11, round(3500 / 11, 2)], [$admin->json('data.totals.days_left'), $admin->json('data.totals.needed_per_day')]);           // del 21 al 31 de octubre

        $vera = $this->section($this->vera, 'goals');
        $this->assertSame(['Vera'], array_column($vera->json('data.rows'), 'seller'));
        $this->assertEquals([3000, 1500, 50.0], [$vera->json('data.totals.goal'), $vera->json('data.totals.actual'), $vera->json('data.totals.compliance_pct')]);
    }

    public function test_credit_is_scoped_to_the_sellers_and_buckets_add_up(): void
    {
        $this->seedBusiness();
        $all = $this->section($this->admin, 'credit')->assertOk();
        $this->assertEquals([1500, 1500, 2, 2], [$all->json('data.total'), $all->json('data.overdue'), $all->json('data.invoices'), $all->json('data.customers')]);
        $this->assertEquals(1500, array_sum(array_column($all->json('data.buckets'), 'amount')));
        $this->assertSame('Cliente CV', $all->json('data.top_debtors.0.name'));

        $this->assertEquals([1000, 1], [$this->section($this->vera, 'credit')->json('data.total'), $this->section($this->vera, 'credit')->json('data.invoices')]);
        $this->assertEquals(1000, $this->section($this->supervisor, 'credit')->json('data.total'));
        $this->assertEquals(500, $this->section($this->beto, 'credit')->json('data.total'));

        PaymentPromise::create(['company_id' => $this->a->id, 'customer_code' => 'CV', 'customer_name' => 'Cliente CV', 'made_on' => '2026-10-20', 'promised_on' => '2026-10-25', 'amount' => 300, 'user_id' => $this->admin->id]);
        PaymentPromise::create(['company_id' => $this->a->id, 'customer_code' => 'CB', 'customer_name' => 'Cliente CB', 'made_on' => '2026-10-10', 'promised_on' => '2026-10-15', 'amount' => 100, 'user_id' => $this->admin->id]);
        $promises = $this->section($this->vera, 'credit')->json('data.promises');
        $this->assertEquals([1, 300, 0], [$promises['due_next_7_days'], $promises['due_amount'], $promises['broken_30_days']]);
        $this->assertSame(['Cliente CV'], array_column($promises['attention'], 'name'));                       // la de Beto no es de sus clientes
        $this->assertSame(['broken', 'open'], array_column($this->section($this->admin, 'credit')->json('data.promises.attention'), 'status'));
    }

    public function test_quotations_follow_the_scope_of_each_role(): void
    {
        $this->seedBusiness();
        $all = $this->section($this->admin, 'quotations')->assertOk();
        $this->assertEquals([2, 600, 1, 0, 2], [$all->json('data.open.count'), $all->json('data.open.amount'), $all->json('data.expiring.count'), $all->json('data.expired_unclosed'), $all->json('data.issued.count')]);

        $vera = $this->section($this->vera, 'quotations');
        $this->assertEquals([1, 1, 1], [$vera->json('data.open.count'), $vera->json('data.expiring.count'), $vera->json('data.issued.count')]);
        $this->assertEquals(0, $this->section($this->beto, 'quotations')->json('data.expiring.count'));
    }

    public function test_the_company_filter_is_limited_to_the_scope_and_the_comparison_needs_several_companies(): void
    {
        $this->seedBusiness();
        $this->section($this->admin, 'sales', '?company_id='.$this->b->id)->assertOk()->assertJsonPath('data.current.documents', 0);
        $this->section($this->vera, 'sales', '?company_id='.$this->b->id)->assertNotFound();                          // compañía fuera de su ámbito
        $rows = $this->section($this->admin, 'companies')->assertOk()->json('data.rows');
        $this->assertSame(['Alfa'], array_column($rows, 'company'));
        $this->assertEquals(75.0, $rows[0]['change_pct']);
        $this->section($this->admin, 'sales', '?period=semestre')->assertUnprocessable();
    }

    public function test_a_seller_without_linked_seller_record_sees_nothing_instead_of_everything(): void
    {
        $this->seedBusiness();
        $lonely = User::factory()->role(Role::VENDEDOR)->create();
        $lonely->companies()->attach($this->a->id);

        $this->assertSame(0, $this->actingAs($lonely, 'sanctum')->getJson('/api/v1/dashboard')->json('data.linked_sellers'));
        $this->assertEquals(0, $this->section($lonely, 'sales')->json('data.current.net_total'));
        $this->assertEquals(0, $this->section($lonely, 'credit')->json('data.total'));
    }

    public function test_followup_counts_overdue_today_and_customers_without_recent_contact_in_each_scope(): void
    {
        $this->seedBusiness();
        $cv = Customer::where('code', 'CV')->first();
        $cb = Customer::where('code', 'CB')->first();
        $action = fn (Customer $c, string $at) => CustomerInteraction::create(['company_id' => $this->a->id, 'customer_id' => $c->id, 'user_id' => $this->admin->id, 'channel' => 'call', 'direction' => 'outbound', 'outcome' => 'spoke', 'occurred_at' => now()->subDays(3), 'next_action' => 'Llamar', 'next_action_at' => $at]);
        $action($cv, '2026-10-19 10:00:00');                                    // vencida
        $action($cv, '2026-10-21 15:00:00');                                    // de hoy
        $action($cb, '2026-10-18 10:00:00');                                    // vencida de otro equipo

        $all = $this->section($this->admin, 'followup')->assertOk();
        $this->assertEquals([2, 1, 30], [$all->json('data.overdue'), $all->json('data.today'), $all->json('data.no_contact.days')]);
        $this->assertEquals([1, 1], [$this->section($this->vera, 'followup')->json('data.overdue'), $this->section($this->vera, 'followup')->json('data.today')]);
        $this->assertEquals(1, $this->section($this->supervisor, 'followup')->json('data.overdue'));                 // solo la de su equipo
        $this->assertIsArray($all->json('data.no_contact.top'));
        $this->assertSame(['Cliente CB', 'Cliente CV', 'Cliente CV'], array_column(array_column($all->json('data.actions'), 'customer'), 'name'));       // vencidas primero y luego las de hoy
        $this->assertSame(['Cliente CV', 'Cliente CV'], array_column(array_column($this->section($this->vera, 'followup')->json('data.actions'), 'customer'), 'name'));
    }

    public function test_dashboard_figures_match_the_detail_screens(): void
    {
        $this->seedBusiness();
        $this->actingAs($this->admin, 'sanctum');

        $dash = $this->getJson('/api/v1/dashboard/sales')->json('data');
        $detail = $this->getJson('/api/v1/sales/summary?from=2026-10-01&to=2026-10-21&compare_from=2026-09-01&compare_to=2026-09-21')->assertOk()->json('data');
        $this->assertEquals([$detail['current']['net_total'], $detail['current']['documents'], $detail['current']['average_ticket'], $detail['previous']['net_total'], $detail['change_pct']['net_total']],
            [$dash['current']['net_total'], $dash['current']['documents'], $dash['current']['average_ticket'], $dash['previous']['net_total'], $dash['previous']['change_pct']]);

        $goals = $this->getJson('/api/v1/dashboard/goals')->json('data');
        $meta = $this->getJson('/api/v1/sales/goals?month=2026-10')->assertOk()->json('meta');
        $this->assertEquals([$meta['totals']['effective_goal'], $meta['totals']['actual'], $meta['totals']['compliance_pct']], [$goals['totals']['goal'], $goals['totals']['actual'], $goals['totals']['compliance_pct']]);

        $credit = $this->getJson('/api/v1/dashboard/credit')->json('data');
        $aging = $this->getJson('/api/v1/credit/aging')->assertOk()->json('data');
        $this->assertEquals($aging['totals']['total'], $credit['total']);
        $this->assertEquals(array_map(fn (string $b) => $aging['totals'][$b], $aging['buckets']), array_column($credit['buckets'], 'amount'));
        $this->assertSame(array_slice(array_column($aging['customers'], 'name'), 0, 2), array_column($credit['top_debtors'], 'name'));
    }
}
