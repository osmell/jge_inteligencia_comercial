<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class SellerGoalsApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $a1;

    private Seller $ana;

    private Seller $beto;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->b = Company::factory()->create(['code' => 'B']);
        $this->a1 = Branch::factory()->create(['company_id' => $this->a->id]);
        $b1 = Branch::factory()->create(['company_id' => $this->b->id]);
        $this->ana = Seller::create(['company_id' => $this->a->id, 'name' => 'Ana', 'normalized_name' => 'ana']);
        $this->beto = Seller::create(['company_id' => $this->a->id, 'name' => 'Beto', 'normalized_name' => 'beto']);
        $otro = Seller::create(['company_id' => $this->b->id, 'name' => 'Otro', 'normalized_name' => 'otro']);

        // Octubre: Ana 600 + 400, Beto 100. Septiembre: Ana 1000. Agosto: Ana 500. Otra compañía: 900.
        $this->sale($this->a1, $this->ana, 'O1', '2026-10-05', 600);
        $this->sale($this->a1, $this->ana, 'O2', '2026-10-10', 400);
        $this->sale($this->a1, $this->beto, 'O3', '2026-10-10', 100);
        $this->sale($this->a1, $this->ana, 'S1', '2026-09-30', 1000);
        $this->sale($this->a1, $this->ana, 'G1', '2026-08-31', 500);
        $this->sale($b1, $otro, 'X1', '2026-10-10', 900);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function sale(Branch $branch, Seller $seller, string $number, string $date, int $net): void
    {
        $doc = SalesDocument::factory()->create([
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'series' => 'S', 'number' => $number,
            'issued_on' => $date, 'seller_id' => $seller->id, 'lines_count' => 1,
        ]);
        SalesLine::create([
            'sales_document_id' => $doc->id, 'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'issued_on' => $date,
            'line_number' => 1, 'product_code' => 'P', 'product_name' => 'P', 'category_name' => 'C', 'units' => 1, 'unit_cost' => 0,
            'line_cost_total' => 0, 'unit_price' => 0, 'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net,
        ]);
    }

    private function save(array $goals, ?User $as = null, string $month = '2026-10'): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->putJson('/api/v1/sales/goals', ['month' => $month, 'goals' => $goals]);
    }

    private function progress(string $month = '2026-10', ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->getJson('/api/v1/sales/goals?month='.$month);
    }

    private function row(array $rows, Seller $seller): array
    {
        return collect($rows)->firstWhere('seller_id', $seller->id);
    }

    public function test_closed_month_compliance_and_traffic_light(): void
    {
        CarbonImmutable::setTestNow('2026-11-05');
        $this->save([
            ['seller_id' => $this->ana->id, 'amount' => 800],     // 1000 vendidos → 125 %
            ['seller_id' => $this->beto->id, 'amount' => 200],    // 100 vendidos → 50 %
        ])->assertOk()->assertJsonPath('data.changed', 2);

        $response = $this->progress()->assertOk();
        $rows = $response->json('data');

        $ana = $this->row($rows, $this->ana);
        $this->assertEquals(125.0, $ana['compliance_pct']);
        $this->assertSame('met', $ana['status']);
        $beto = $this->row($rows, $this->beto);
        $this->assertEquals(50.0, $beto['compliance_pct']);
        $this->assertSame('danger', $beto['status']);
        $this->assertSame('closed', $response->json('meta.state'));
        $this->assertNull($ana['projected']);
        $this->assertFalse($beto['behind_pace']);

        $totals = $response->json('meta.totals');
        $this->assertEquals(1000, $totals['effective_goal']);
        $this->assertEquals(1100, $totals['actual']);
        $this->assertEquals(110.0, $totals['compliance_pct']);
        $this->assertCount(3, $rows);                                 // el administrador ve también la otra compañía
    }

    public function test_new_seller_and_absences_prorate_the_goal(): void
    {
        CarbonImmutable::setTestNow('2026-11-05');
        // Octubre tiene 31 días: alta el día 16 → 16 días; 4 de ausencia → 12 elegibles.
        $this->save([['seller_id' => $this->ana->id, 'amount' => 3100, 'days_off' => 4, 'starts_on' => '2026-10-16']]);

        $ana = $this->row($this->progress()->json('data'), $this->ana);
        $this->assertSame(12, $ana['eligible_days']);
        $this->assertEquals(1200, $ana['effective_goal']);            // 3100 × 12 / 31
        $this->assertSame('2026-10-16', $ana['starts_on']);
        $this->assertSame('warning', $ana['status']);                 // vendió 1000 de 1200: 83.33 %
    }

    public function test_leaving_seller_keeps_goal_until_departure_and_full_absence_is_exempt(): void
    {
        CarbonImmutable::setTestNow('2026-11-05');
        $this->save([
            ['seller_id' => $this->ana->id, 'amount' => 3100, 'ends_on' => '2026-10-10'],
            ['seller_id' => $this->beto->id, 'amount' => 3100, 'days_off' => 31],
        ]);

        $rows = $this->progress()->json('data');
        $this->assertEquals(1000, $this->row($rows, $this->ana)['effective_goal']);  // 10 días de 31
        $beto = $this->row($rows, $this->beto);
        $this->assertSame('exempt', $beto['status']);
        $this->assertNull($beto['compliance_pct']);
    }

    public function test_current_month_projection_and_pace_alert(): void
    {
        CarbonImmutable::setTestNow('2026-10-16');     // 16 de 31 días
        $this->save([
            ['seller_id' => $this->ana->id, 'amount' => 3100],    // esperado a la fecha 1600, vendió 1000 → ritmo 62.5 %
            ['seller_id' => $this->beto->id, 'amount' => 100],    // esperado 51.61, vendió 100
        ]);

        $response = $this->progress()->assertOk();
        $this->assertSame('current', $response->json('meta.state'));
        $ana = $this->row($response->json('data'), $this->ana);
        $this->assertEquals(1600.0, $ana['expected_to_date']);
        $this->assertEquals(62.5, $ana['pace_pct']);
        $this->assertTrue($ana['behind_pace']);
        $this->assertEquals(1937.5, $ana['projected']);                // 1000 / (16/31)
        $this->assertFalse($this->row($response->json('data'), $this->beto)['behind_pace']);
        $this->assertSame(1, $response->json('meta.totals.behind_pace'));
    }

    public function test_pace_alert_waits_until_enough_of_the_month_has_passed(): void
    {
        CarbonImmutable::setTestNow('2026-10-03');
        $this->save([['seller_id' => $this->ana->id, 'amount' => 100000]]);

        $this->assertFalse($this->row($this->progress()->json('data'), $this->ana)['behind_pace']);
    }

    public function test_sellers_without_goal_are_listed_and_empty_amount_deletes_the_goal(): void
    {
        CarbonImmutable::setTestNow('2026-11-05');
        $this->save([['seller_id' => $this->ana->id, 'amount' => 500]]);
        $this->assertSame('no_goal', $this->row($this->progress()->json('data'), $this->beto)['status']);

        $this->save([['seller_id' => $this->ana->id, 'amount' => null]])->assertOk();
        $this->assertSame('no_goal', $this->row($this->progress()->json('data'), $this->ana)['status']);
        $this->assertDatabaseCount('seller_goals', 0);
    }

    public function test_suggestions_average_previous_months_with_sales_and_apply_growth(): void
    {
        CarbonImmutable::setTestNow('2026-10-16');
        $data = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/sales/goals/suggestions?month=2026-10&months=3&growth_pct=10')->assertOk()->json('data');

        $this->assertCount(1, $data);                                  // Beto no vendió en los 3 meses previos
        $this->assertSame($this->ana->id, $data[0]['seller_id']);
        $this->assertSame(2, $data[0]['months_used']);                 // septiembre y agosto
        $this->assertEquals(750, $data[0]['average']);
        $this->assertEquals(825, $data[0]['suggested_amount']);

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/sales/goals/suggestions?month=2026-10&months=13')->assertUnprocessable();
    }

    public function test_saving_is_audited_with_old_and_new_values_and_records_the_source(): void
    {
        $this->save([['seller_id' => $this->ana->id, 'amount' => 800, 'source' => 'suggested']])->assertOk();
        $this->save([['seller_id' => $this->ana->id, 'amount' => 900]])->assertOk();
        $this->save([['seller_id' => $this->ana->id, 'amount' => 900]])->assertOk()->assertJsonPath('data.changed', 0);

        $logs = AuditLog::where('event', 'goals.updated')->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertNull($logs[0]->old_values['amount']);
        $this->assertSame('suggested', $logs[0]->new_values['source']);
        $this->assertEquals(800, $logs[1]->old_values['amount']);
        $this->assertEquals(900, $logs[1]->new_values['amount']);
    }

    public function test_permissions_and_scope(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['sales.view']])->assertOk();

        // Con solo sales.view ve el avance, pero no puede capturar.
        $this->progress('2026-10', $user)->assertOk();
        $this->save([['seller_id' => $this->ana->id, 'amount' => 100]], $user)->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['sales.view', 'goals.manage']])->assertOk();
        $user->unsetRelations();
        $this->save([['seller_id' => $this->ana->id, 'amount' => 100]], $user)->assertOk();

        // Un vendedor de otra compañía no se puede tocar.
        $otro = Seller::where('normalized_name', 'otro')->first();
        $this->save([['seller_id' => $otro->id, 'amount' => 100]], $user)->assertNotFound();

        $names = array_column($this->progress('2026-10', $user)->json('data'), 'seller');
        $this->assertSame(['Ana', 'Beto'], $names);

        $sin = User::factory()->role(Role::VENDEDOR)->create();
        $this->progress('2026-10', $sin)->assertForbidden();
    }

    public function test_a_user_limited_to_some_branches_does_not_see_goals(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $user->branches()->attach($this->a1);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['sales.view']])->assertOk();

        $this->assertSame([], $this->progress('2026-10', $user)->assertOk()->json('data'));
    }

    public function test_validation(): void
    {
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/sales/goals')->assertUnprocessable();
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/sales/goals?month=2026-13')->assertUnprocessable();
        $this->save([['seller_id' => $this->ana->id, 'amount' => -5]])->assertUnprocessable();
        $this->save([['seller_id' => $this->ana->id, 'amount' => 5, 'days_off' => 40]])->assertUnprocessable();
        $this->save([['seller_id' => $this->ana->id, 'amount' => 5, 'starts_on' => '2026-10-20', 'ends_on' => '2026-10-01']])->assertUnprocessable();
        $this->save([])->assertUnprocessable();
    }
}
