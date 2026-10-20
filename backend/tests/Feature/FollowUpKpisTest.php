<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class FollowUpKpisTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $branch;

    private User $admin;

    private User $vendedor;

    private Customer $c1;

    private Customer $c2;

    private Customer $c3;

    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create(['name' => 'Admin']);
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->vendedor = User::factory()->role(Role::VENDEDOR)->create(['name' => 'Vera']);
        $this->vendedor->companies()->attach($this->a->id);
        $vera = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vendedor->id]);
        $this->c1 = Customer::create(['company_id' => $this->a->id, 'code' => 'C1', 'name' => 'Uno', 'seller_id' => $vera->id]);
        $this->c2 = Customer::create(['company_id' => $this->a->id, 'code' => 'C2', 'name' => 'Dos', 'seller_id' => $vera->id]);
        $this->c3 = Customer::create(['company_id' => $this->a->id, 'code' => 'C3', 'name' => 'Tres']);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->vendedor->role_id, ['permissions' => ['customers.view', 'interactions.manage']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function contact(Customer $c, User $by, string $outcome, string $at, array $over = []): CustomerInteraction
    {
        return CustomerInteraction::create(['company_id' => $this->a->id, 'customer_id' => $c->id, 'user_id' => $by->id, 'channel' => 'call', 'direction' => 'outbound', 'outcome' => $outcome, 'occurred_at' => $at, ...$over]);
    }

    private function sale(string $code, string $on): void
    {
        SalesDocument::factory()->create(['company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'customer_code' => $code, 'issued_on' => $on]);
    }

    private function quote(Customer $c, User $by, string $status, string $issuedAt, array $over = []): Quotation
    {
        $this->sequence++;

        return Quotation::create([
            'company_id' => $this->a->id, 'customer_id' => $c->id, 'user_id' => $by->id, 'sequence' => $this->sequence, 'number' => 'COT-'.$this->sequence,
            'status' => $status, 'amount' => 100, 'valid_until' => '2026-12-31', 'issued_at' => $issuedAt, ...$over,
        ]);
    }

    /** @return array<string, mixed> */
    private function kpis(User $as, array $params = []): array
    {
        return $this->actingAs($as, 'sanctum')->getJson('/api/v1/customers/follow-up-kpis?'.http_build_query(['from' => '2026-10-01', 'to' => '2026-10-31', ...$params]))->assertOk()->json('data');
    }

    public function test_contacts_are_counted_inside_the_period_with_effectiveness(): void
    {
        $this->contact($this->c1, $this->vendedor, 'spoke', '2026-10-05 10:00:00');
        $this->contact($this->c1, $this->vendedor, 'no_answer', '2026-10-06 10:00:00', ['channel' => 'whatsapp']);
        $this->contact($this->c2, $this->vendedor, 'interested', '2026-10-07 10:00:00', ['direction' => 'inbound', 'channel' => 'email']);
        $this->contact($this->c1, $this->vendedor, 'spoke', '2026-09-30 23:00:00');
        $this->contact($this->c1, $this->vendedor, 'spoke', '2026-11-01 00:30:00');
        $this->contact($this->c2, $this->vendedor, 'spoke', '2026-10-31 23:59:00');

        $c = $this->kpis($this->admin)['contacts'];

        $this->assertSame(4, $c['total']);
        $this->assertSame(3, $c['outbound']);
        $this->assertSame(1, $c['inbound']);
        $this->assertSame(2, $c['customers']);
        $this->assertSame(3, $c['effective']);
        $this->assertEquals(75.0, $c['effective_pct']);
        $this->assertSame(['call' => 2, 'whatsapp' => 1, 'email' => 1], $c['by_channel']);
        $this->assertSame(['spoke' => 2, 'no_answer' => 1, 'interested' => 1], $c['by_outcome']);
    }

    public function test_action_compliance_separates_on_time_late_overdue_and_upcoming(): void
    {
        $base = ['occurred_at' => '2026-09-20 10:00:00', 'next_action' => 'x'];
        $this->contact($this->c1, $this->vendedor, 'interested', '2026-09-20 10:00:00', $base + ['next_action_at' => '2026-10-05 09:00:00', 'next_action_done_at' => '2026-10-05 17:00:00']);   // el mismo día: a tiempo
        $this->contact($this->c1, $this->vendedor, 'interested', '2026-09-20 10:00:00', $base + ['next_action_at' => '2026-10-06 09:00:00', 'next_action_done_at' => '2026-10-08 09:00:00']);   // tarde
        $this->contact($this->c1, $this->vendedor, 'interested', '2026-09-20 10:00:00', $base + ['next_action_at' => '2026-10-07 09:00:00']);                                              // vencida abierta
        $this->contact($this->c1, $this->vendedor, 'interested', '2026-09-20 10:00:00', $base + ['next_action_at' => '2026-10-25 09:00:00']);                                              // aún no vence
        $this->contact($this->c1, $this->vendedor, 'interested', '2026-09-20 10:00:00', $base + ['next_action_at' => '2026-09-25 09:00:00']);                                              // fuera del periodo

        $a = $this->kpis($this->admin)['actions'];

        $this->assertEquals(['on_time' => 1, 'late' => 1, 'open_overdue' => 1, 'upcoming' => 1, 'due' => 3, 'on_time_pct' => 33.3, 'completed_pct' => 66.7], $a);
        $this->assertSame(0, $this->kpis($this->admin)['contacts']['total']);
    }

    public function test_new_and_recovered_customers_describe_the_portfolio(): void
    {
        $this->sale('OLD', '2026-09-01');       // inicio del historial cargado
        $this->sale('C1', '2026-10-03');        // primera compra dentro del historial: nuevo
        $this->sale('C2', '2026-06-01');
        $this->sale('C2', '2026-10-10');        // 131 días sin comprar: recuperado
        $this->sale('C3', '2026-09-20');
        $this->sale('C3', '2026-10-12');        // 22 días: sigue activo

        $c = $this->kpis($this->admin)['customers'];

        $this->assertSame(['new' => 1, 'recovered' => 1, 'inactive_days' => 30], $c);
    }

    public function test_quotation_conversion_is_a_cohort_of_those_issued_in_the_period(): void
    {
        $this->quote($this->c1, $this->vendedor, 'converted', '2026-10-02 10:00:00', ['amount' => 1000, 'converted_at' => '2026-10-07 10:00:00']);
        $this->quote($this->c1, $this->vendedor, 'rejected', '2026-10-03 10:00:00', ['amount' => 500, 'loss_reason' => 'price']);
        $this->quote($this->c2, $this->admin, 'issued', '2026-10-04 10:00:00', ['amount' => 300]);
        $this->quote($this->c2, $this->admin, 'sent', '2026-10-05 10:00:00', ['amount' => 200, 'valid_until' => '2026-10-10']);        // vencida
        $this->quote($this->c2, $this->admin, 'accepted', '2026-10-06 10:00:00', ['amount' => 100]);                                   // aceptada sin factura
        $this->quote($this->c1, $this->vendedor, 'converted', '2026-09-15 10:00:00', ['converted_at' => '2026-10-02 10:00:00']);       // otra cohorte
        $this->quote($this->c1, $this->vendedor, 'draft', '2026-10-08 10:00:00', ['issued_at' => null]);                                  // borrador

        $q = $this->kpis($this->admin)['quotations'];

        $this->assertSame(5, $q['issued']);
        $this->assertSame('2100.00', $q['issued_amount']);
        $this->assertSame(1, $q['converted']);
        $this->assertSame('1000.00', $q['converted_amount']);
        $this->assertSame(1, $q['accepted_without_invoice']);
        $this->assertSame(1, $q['rejected']);
        $this->assertSame(1, $q['expired']);
        $this->assertSame(1, $q['open']);
        $this->assertEquals(20.0, $q['conversion_pct']);
        $this->assertSame(47.6, $q['amount_conversion_pct']);
        $this->assertEquals(5.0, $q['avg_days_to_convert']);
        $this->assertSame(['price' => 1], $q['loss_reasons']);
    }

    public function test_the_breakdown_by_user_adds_up_each_person_work(): void
    {
        $this->contact($this->c1, $this->vendedor, 'spoke', '2026-10-05 10:00:00');
        $this->contact($this->c2, $this->vendedor, 'no_answer', '2026-10-06 10:00:00');
        $this->contact($this->c3, $this->admin, 'spoke', '2026-10-07 10:00:00', ['next_action' => 'x', 'next_action_at' => '2026-10-08 09:00:00', 'next_action_done_at' => '2026-10-08 10:00:00']);
        $this->quote($this->c1, $this->vendedor, 'converted', '2026-10-02 10:00:00', ['converted_at' => '2026-10-04 10:00:00']);
        $this->quote($this->c1, $this->vendedor, 'issued', '2026-10-03 10:00:00');

        $rows = collect($this->kpis($this->admin)['by_user'])->keyBy('user.name');

        $this->assertSame(['Vera', 'Admin'], $rows->keys()->all());
        $this->assertSame(2, $rows['Vera']['contacts']);
        $this->assertSame(1, $rows['Vera']['effective']);
        $this->assertSame(2, $rows['Vera']['quotations_issued']);
        $this->assertEquals(50.0, $rows['Vera']['conversion_pct']);
        $this->assertSame(1, $rows['Admin']['actions_due']);
        $this->assertEquals(100.0, $rows['Admin']['compliance_pct']);
        $this->assertNull($rows['Admin']['conversion_pct']);
    }

    public function test_a_seller_only_gets_numbers_of_their_customers(): void
    {
        $this->contact($this->c1, $this->vendedor, 'spoke', '2026-10-05 10:00:00');
        $this->contact($this->c3, $this->admin, 'spoke', '2026-10-06 10:00:00');
        $this->quote($this->c3, $this->admin, 'issued', '2026-10-06 10:00:00');
        $this->sale('OLD', '2026-09-01');
        $this->sale('C1', '2026-10-03');
        $this->sale('C3', '2026-10-04');

        $data = $this->kpis($this->vendedor);

        $this->assertSame(1, $data['contacts']['total']);
        $this->assertSame(0, $data['quotations']['issued']);
        $this->assertSame(1, $data['customers']['new']);
        $this->assertSame(2, $this->kpis($this->admin)['customers']['new']);
        $this->assertSame(['Vera'], array_column(array_column($data['by_user'], 'user'), 'name'));
    }

    public function test_it_filters_by_seller(): void
    {
        $this->contact($this->c1, $this->vendedor, 'spoke', '2026-10-05 10:00:00');
        $this->contact($this->c3, $this->admin, 'spoke', '2026-10-06 10:00:00');
        $sellerId = Seller::where('name', 'Vera')->value('id');

        $this->assertSame(1, $this->kpis($this->admin, ['seller_id' => $sellerId])['contacts']['total']);
        $this->assertSame(2, $this->kpis($this->admin)['contacts']['total']);
    }

    public function test_period_validation_and_permissions(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->getJson('/api/v1/customers/follow-up-kpis')->assertUnprocessable()->assertJsonValidationErrors(['from', 'to']);
        $this->getJson('/api/v1/customers/follow-up-kpis?from=2026-10-10&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);
        $this->getJson('/api/v1/customers/follow-up-kpis?from=2025-01-01&to=2026-10-01')->assertUnprocessable()->assertJsonValidationErrors(['to']);

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->vendedor->role_id, ['permissions' => ['customers.view']])->assertOk();
        $this->actingAs($this->vendedor->fresh(), 'sanctum')->getJson('/api/v1/customers/follow-up-kpis?from=2026-10-01&to=2026-10-31')->assertForbidden();
    }
}
