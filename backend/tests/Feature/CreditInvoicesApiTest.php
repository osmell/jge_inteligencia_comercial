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
use App\Services\CreditAging;
use App\Services\CreditLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CreditInvoicesApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $branchA;

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
        $this->b = Company::factory()->create(['code' => 'B']);
        $this->branchA = Branch::factory()->create(['company_id' => $this->a->id]);
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

    private function invoice(string $number, string $issued, float $total, ?Seller $seller = null, string $customer = 'C1', array $over = []): CreditInvoice
    {
        $invoice = CreditInvoice::create([
            'company_id' => $this->a->id, 'branch_id' => $this->branchA->id, 'series' => 'A', 'number' => $number, 'issued_on' => $issued, 'customer_code' => $customer, 'customer_name' => "Cliente $customer",
            'seller_id' => ($seller ?? $this->vera)->id, 'operator_id' => $this->operator->id, 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'total' => $total, 'content_hash' => $number, ...$over,
        ]);
        CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => $invoice->id, 'type' => 'invoice', 'amount' => $total, 'occurred_on' => $issued, 'created_at' => now()]);

        return $invoice;
    }

    /** @return list<string> */
    private function documents(array $params = [], ?User $as = null): array
    {
        $r = $this->actingAs($as ?? $this->admin, 'sanctum')->getJson('/api/v1/credit/invoices?'.http_build_query($params))->assertOk();

        return array_column($r->json('data'), 'document');
    }

    public function test_aging_buckets_follow_the_agreed_ranges(): void
    {
        $this->assertSame(['current', '1-30', '31-60', '61-90', '91-120', '121+'], CreditAging::buckets());
        $this->assertSame(
            ['current', '1-30', '1-30', '31-60', '31-60', '61-90', '91-120', '91-120', '121+'],
            array_map(CreditAging::bucket(...), [0, 1, 30, 31, 60, 90 - 29, 91, 120, 121]),
        );
        $this->assertSame('121+', CreditAging::bucket(400));
        $this->assertSame('current', CreditAging::bucket(-3));
    }

    public function test_it_lists_invoices_with_balance_age_and_bucket(): void
    {
        $inv = $this->invoice('1', '2026-09-10', 1000);   // 40 días
        app(CreditLedger::class)->record($inv, CreditMovement::PAYMENT, '250', Carbon::parse('2026-09-20'), $this->admin);

        $row = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/credit/invoices')->assertOk()
            ->assertJsonPath('meta.open_count', 1)->assertJsonPath('meta.open_balance', '750.00')->json('data.0');

        $this->assertSame(['A-1', '1000.00', '750.00', '250.00', 40, '31-60', false], [$row['document'], $row['total'], $row['balance'], $row['paid'], $row['age_days'], $row['bucket'], $row['sales_linked']]);
        $this->assertSame(['code' => 'C1', 'name' => 'Cliente C1'], $row['customer']);
    }

    public function test_status_filter_separates_open_and_paid_invoices(): void
    {
        $paid = $this->invoice('1', '2026-10-01', 100);
        app(CreditLedger::class)->record($paid, CreditMovement::PAYMENT, '100', Carbon::parse('2026-10-05'), $this->admin);
        $this->invoice('2', '2026-10-02', 200);

        $this->assertSame(['A-2'], $this->documents());
        $this->assertSame(['A-2'], $this->documents(['status' => 'open']));
        $this->assertSame(['A-1'], $this->documents(['status' => 'paid']));
        $this->assertSame(['A-1', 'A-2'], $this->documents(['status' => 'all']));
        $this->assertNull($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/credit/invoices?status=paid')->json('data.0.bucket'));
    }

    public function test_bucket_filter_uses_the_invoice_date(): void
    {
        $this->invoice('today', '2026-10-20', 10);
        $this->invoice('d30', '2026-09-20', 20);      // 30 días
        $this->invoice('d31', '2026-09-19', 30);      // 31 días
        $this->invoice('d121', '2026-06-21', 40);     // 121 días
        $this->invoice('d200', '2026-04-03', 50);

        $this->assertSame(['A-today'], $this->documents(['bucket' => 'current']));
        $this->assertSame(['A-d30'], $this->documents(['bucket' => '1-30']));
        $this->assertSame(['A-d31'], $this->documents(['bucket' => '31-60']));
        $this->assertSame(['A-d200', 'A-d121'], $this->documents(['bucket' => '121+']));
        $this->getJson('/api/v1/credit/invoices?bucket=7-9')->assertUnprocessable()->assertJsonValidationErrors(['bucket']);
    }

    public function test_it_filters_by_customer_and_text_and_escapes_wildcards(): void
    {
        $this->invoice('10', '2026-10-01', 10, null, 'C1');
        $this->invoice('11', '2026-10-02', 10, null, 'C2');

        $this->assertSame(['A-11'], $this->documents(['company_id' => $this->a->id, 'code' => 'C2']));
        $this->assertSame(['A-10'], $this->documents(['q' => 'a-10']));
        $this->assertSame(['A-10', 'A-11'], $this->documents(['q' => 'cliente']));
        $this->assertSame([], $this->documents(['q' => '%']));
        $this->getJson('/api/v1/credit/invoices?code=C1')->assertUnprocessable()->assertJsonValidationErrors(['company_id']);
        $this->getJson('/api/v1/credit/invoices?status=bogus')->assertUnprocessable();
    }

    public function test_a_seller_only_sees_their_own_invoices(): void
    {
        $this->invoice('1', '2026-10-01', 100, $this->vera);
        $other = $this->invoice('2', '2026-10-01', 200, $this->otro);

        $this->assertSame(['A-1'], $this->documents([], $this->vendedor));
        $this->actingAs($this->vendedor, 'sanctum')->getJson("/api/v1/credit/invoices/{$other->id}")->assertNotFound();
        $this->assertSame(['A-1', 'A-2'], $this->documents());
    }

    public function test_the_detail_shows_the_movements_and_which_were_reversed(): void
    {
        $inv = $this->invoice('1', '2026-10-01', 1000);
        $ledger = app(CreditLedger::class);
        $payment = $ledger->record($inv, CreditMovement::PAYMENT, '300', Carbon::parse('2026-10-05'), $this->admin, ['method' => 'efectivo', 'reference' => 'R-1']);
        $ledger->reverse($payment, $this->admin, 'Error de captura');

        $r = $this->actingAs($this->admin, 'sanctum')->getJson("/api/v1/credit/invoices/{$inv->id}")->assertOk()
            ->assertJsonPath('data.balance', '1000.00')->assertJsonPath('data.branch', $this->branchA->name)->json('data');

        $this->assertSame(['invoice', 'payment', 'reversal'], array_column($r['movements'], 'type'));
        $this->assertSame(['1000.00', '-300.00', '300.00'], array_column($r['movements'], 'amount'));
        $this->assertSame([false, true, false], array_column($r['movements'], 'reversed'));
        $this->assertSame($payment->id, $r['movements'][2]['reverses_id']);
        $this->assertSame('efectivo', $r['movements'][1]['method']);
    }

    public function test_it_needs_the_credit_permission(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->vendedor->role_id, ['permissions' => ['customers.view']])->assertOk();
        $this->actingAs($this->vendedor->fresh(), 'sanctum')->getJson('/api/v1/credit/invoices')->assertForbidden();
    }

    public function test_another_company_scope_is_respected(): void
    {
        $this->invoice('1', '2026-10-01', 100);
        $branchB = Branch::factory()->create(['company_id' => $this->b->id]);
        $sellerB = Seller::create(['company_id' => $this->b->id, 'name' => 'B', 'normalized_name' => 'b']);
        $operatorB = Operator::create(['company_id' => $this->b->id, 'name' => 'Cb', 'normalized_name' => 'cb']);
        CreditInvoice::create([
            'company_id' => $this->b->id, 'branch_id' => $branchB->id, 'series' => 'B', 'number' => '1', 'issued_on' => '2026-10-01', 'customer_code' => 'X', 'customer_name' => 'X', 'seller_id' => $sellerB->id,
            'operator_id' => $operatorB->id, 'subtotal' => 5, 'discount_total' => 0, 'tax_total' => 0, 'total' => 5, 'content_hash' => 'b',
        ]);
        $user = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $user->companies()->attach($this->a->id);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['credit.view']])->assertOk();

        $this->assertSame(['A-1'], $this->documents([], $user->fresh()));
    }
}
