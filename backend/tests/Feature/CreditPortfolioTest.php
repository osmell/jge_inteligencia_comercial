<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CreditPortfolioTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $branch;

    private User $admin;

    private User $vendedor;

    private Seller $vera;

    private Seller $otro;

    private Operator $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $this->vendedor->companies()->attach($this->a->id);
        $this->vera = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vendedor->id]);
        $this->otro = Seller::create(['company_id' => $this->a->id, 'name' => 'Otro', 'normalized_name' => 'otro']);
        $this->operator = Operator::create(['company_id' => $this->a->id, 'name' => 'Caja', 'normalized_name' => 'caja']);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->vendedor->role_id, ['permissions' => ['credit.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $number, string $issued, float $total, string $customer, ?Seller $seller = null): CreditInvoice
    {
        $i = CreditInvoice::create([
            'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'A', 'number' => $number, 'issued_on' => $issued, 'customer_code' => $customer, 'customer_name' => "Cliente $customer",
            'seller_id' => ($seller ?? $this->vera)->id, 'operator_id' => $this->operator->id, 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'total' => $total, 'content_hash' => $number,
        ]);
        CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => $i->id, 'type' => 'invoice', 'amount' => $total, 'occurred_on' => $issued, 'created_at' => now()]);

        return $i;
    }

    public function test_aging_groups_open_balances_by_customer_and_bucket(): void
    {
        $this->invoice('1', '2026-10-20', 100, 'C1');            // al día
        $this->invoice('2', '2026-09-20', 200, 'C1');            // 30 días
        $this->invoice('3', '2026-05-01', 700, 'C2');            // más de 120
        $paid = $this->invoice('4', '2026-09-01', 50, 'C2');
        CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => $paid->id, 'type' => 'payment', 'amount' => -50, 'occurred_on' => '2026-09-05', 'created_at' => now()]);

        $r = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/credit/aging')->assertOk();
        $r->assertJsonPath('data.totals.total', '1000.0000')->assertJsonPath('data.totals.current', '100.0000')->assertJsonPath('data.totals.1-30', '200.0000')->assertJsonPath('data.totals.121+', '700.0000');
        $this->assertSame(['C2', 'C1'], array_column($r->json('data.customers'), 'code'));
        $this->assertSame(['700.0000', 2, 172], [$r->json('data.customers.0.total'), $r->json('data.customers.1.invoices'), $r->json('data.customers.0.oldest_days')]);
        $this->assertSame(2, $r->json('meta.customers_total'));
    }

    public function test_a_seller_only_sees_their_own_portfolio(): void
    {
        $this->invoice('1', '2026-10-01', 100, 'C1');
        $this->invoice('2', '2026-10-01', 900, 'C2', $this->otro);

        $this->actingAs($this->vendedor, 'sanctum')->getJson('/api/v1/credit/aging')->assertOk()->assertJsonPath('data.totals.total', '100.0000');
    }

    public function test_the_export_is_csv_audited_and_safe_against_formulas(): void
    {
        $i = $this->invoice('1', '2026-10-01', 100, '=C1');
        $i->forceFill([])->saveQuietly();

        $r = $this->actingAs($this->admin, 'sanctum')->get('/api/v1/credit/aging/export')->assertOk();
        $csv = $r->streamedContent();
        $this->assertStringContainsString('Más de 120 días', $csv);
        $this->assertStringContainsString("'=C1", $csv);
        $this->assertDatabaseHas('audit_logs', ['event' => 'credit.aging_exported']);
    }

    public function test_the_customer_summary_includes_current_returns_only(): void
    {
        $inv = $this->invoice('1', '2026-10-01', 1000, 'C1');
        $this->actingAs($this->admin, 'sanctum');
        $note = fn (string $n, string $amount) => $this->postJson('/api/v1/credit/credit-notes', [
            'company_id' => $this->a->id, 'code' => 'C1', 'occurred_on' => '2026-10-10', 'series' => 'NC', 'number' => $n, 'subtotal' => $amount, 'tax_total' => '0', 'total' => $amount,
            'allocations' => [['invoice_id' => $inv->id, 'amount' => $amount]],
        ])->assertCreated();
        $note('1', '100');
        $note('2', '50');
        $movement = CreditMovement::where('type', 'credit_note')->orderBy('id')->value('id');
        $this->postJson("/api/v1/credit/movements/$movement/reverse", ['note' => 'error'])->assertOk();

        $r = $this->getJson('/api/v1/credit/customer?company_id='.$this->a->id.'&code=C1')->assertOk();
        $r->assertJsonPath('data.returns.count', 1)->assertJsonPath('data.returns.total', '50.0000')->assertJsonPath('data.totals.total', '950.0000')->assertJsonPath('data.open_invoices', 1);
    }

    public function test_it_requires_the_credit_view_permission(): void
    {
        $other = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/credit/aging')->assertForbidden();
        $this->actingAs($other, 'sanctum')->get('/api/v1/credit/aging/export')->assertForbidden();
    }
}
