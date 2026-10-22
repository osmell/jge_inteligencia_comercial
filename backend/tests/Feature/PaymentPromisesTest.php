<?php

declare(strict_types=1);

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

class PaymentPromisesTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private User $admin;

    private User $supervisor;

    private User $vendedor;

    private CreditInvoice $invoice;

    private CreditInvoice $foreign;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $this->vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->supervisor->companies()->attach($this->a->id);
        $this->vendedor->companies()->attach($this->a->id);
        $branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $operator = Operator::create(['company_id' => $this->a->id, 'name' => 'Caja', 'normalized_name' => 'caja']);
        $vera = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vendedor->id]);
        $otro = Seller::create(['company_id' => $this->a->id, 'name' => 'Otro', 'normalized_name' => 'otro']);
        $make = fn (string $n, string $customer, Seller $s) => tap(CreditInvoice::create([
            'company_id' => $this->a->id, 'branch_id' => $branch->id, 'series' => 'A', 'number' => $n, 'issued_on' => '2026-09-01', 'customer_code' => $customer, 'customer_name' => "Cliente $customer",
            'seller_id' => $s->id, 'operator_id' => $operator->id, 'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 0, 'total' => 1000, 'content_hash' => $n,
        ]), fn ($i) => CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => $i->id, 'type' => 'invoice', 'amount' => 1000, 'occurred_on' => '2026-09-01', 'created_at' => now()]));
        $this->invoice = $make('1', 'C1', $vera);
        $this->foreign = $make('2', 'C2', $otro);

        $this->actingAs($this->admin, 'sanctum');
        $this->putJson('/api/v1/admin/role-permissions/'.$this->supervisor->role_id, ['permissions' => ['credit.view', 'credit.payments', 'credit.promises']])->assertOk();
        $this->putJson('/api/v1/admin/role-permissions/'.$this->vendedor->role_id, ['permissions' => ['credit.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function promise(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->supervisor, 'sanctum')->postJson('/api/v1/credit/promises', $over + [
            'company_id' => $this->a->id, 'code' => 'C1', 'promised_on' => '2026-10-25', 'amount' => '400', 'note' => 'Pasa a pagar el viernes',
        ]);
    }

    private function rows(?User $as = null, string $query = ''): array
    {
        return $this->actingAs($as ?? $this->supervisor, 'sanctum')->getJson('/api/v1/credit/promises?status=all'.$query)->assertOk()->json('data');
    }

    private function pay(string $amount, string $on): void
    {
        $this->actingAs($this->supervisor, 'sanctum')->postJson('/api/v1/credit/payments', [
            'company_id' => $this->a->id, 'code' => 'C1', 'occurred_on' => $on, 'method' => 'efectivo', 'allocations' => [['invoice_id' => $this->invoice->id, 'amount' => $amount]],
        ])->assertCreated();
    }

    public function test_it_registers_a_promise_with_validations(): void
    {
        $this->promise()->assertCreated();
        $this->assertDatabaseHas('payment_promises', ['customer_code' => 'C1', 'amount' => '400.0000', 'made_on' => '2026-10-20', 'user_id' => $this->supervisor->id]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'credit.promise_created']);

        $this->promise(['promised_on' => '2026-10-19'])->assertUnprocessable()->assertJsonValidationErrors(['promised_on']);
        $this->promise(['promised_on' => '2027-03-01'])->assertUnprocessable()->assertJsonValidationErrors(['promised_on']);
        $this->promise(['amount' => '0'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->promise(['amount' => '1000.01'])->assertUnprocessable()->assertJsonValidationErrors(['amount']);
        $this->promise(['code' => 'NADA'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->promise(as: $this->vendedor)->assertForbidden();
    }

    public function test_payments_up_to_the_promised_date_keep_the_promise(): void
    {
        $this->promise()->assertCreated();
        $this->assertSame('open', $this->rows()[0]['status']);

        $this->pay('150', '2026-10-20');
        $row = $this->rows()[0];
        $this->assertSame(['open', '150.0000'], [$row['status'], $row['paid']]);

        Carbon::setTestNow('2026-10-25 09:00:00');
        $this->pay('250', '2026-10-25');
        $this->assertSame('kept', $this->rows()[0]['status']);
    }

    public function test_a_promise_without_enough_payments_is_broken_after_its_date(): void
    {
        $this->promise()->assertCreated();
        $this->pay('100', '2026-10-20');

        Carbon::setTestNow('2026-10-25 20:00:00');
        $this->assertSame('open', $this->rows()[0]['status']);

        Carbon::setTestNow('2026-10-27 09:00:00');
        $row = $this->rows()[0];
        $this->assertSame(['broken', 2, '100.0000'], [$row['status'], $row['days_overdue'], $row['paid']]);
    }

    public function test_reversed_payments_and_payments_outside_the_window_do_not_count(): void
    {
        $this->promise()->assertCreated();
        $this->pay('400', '2026-10-20');
        $movement = CreditMovement::where('type', 'payment')->value('id');
        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/movements/$movement/reverse", ['note' => 'error'])->assertOk();

        $this->assertSame(['open', '0.0000'], [$this->rows()[0]['status'], $this->rows()[0]['paid']]);

        // Un abono posterior a la fecha prometida tampoco cumple la promesa.
        Carbon::setTestNow('2026-10-28 09:00:00');
        $this->pay('400', '2026-10-28');
        $this->assertSame('broken', $this->rows()[0]['status']);
    }

    public function test_cancelling_requires_a_reason_and_works_once(): void
    {
        $id = $this->promise()->assertCreated()->json('data.id');

        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/promises/$id/cancel", [])->assertUnprocessable();
        $this->actingAs($this->vendedor, 'sanctum')->postJson("/api/v1/credit/promises/$id/cancel", ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/promises/$id/cancel", ['reason' => 'El cliente cambió la fecha'])->assertOk();
        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/promises/$id/cancel", ['reason' => 'otra'])->assertUnprocessable();

        $this->assertSame(['cancelled', 'El cliente cambió la fecha'], [$this->rows()[0]['status'], $this->rows()[0]['cancel_reason']]);
        $this->assertDatabaseHas('audit_logs', ['event' => 'credit.promise_cancelled']);
    }

    public function test_the_default_list_shows_what_needs_attention(): void
    {
        $this->promise(['promised_on' => '2026-10-22'])->assertCreated();
        $this->promise(['promised_on' => '2026-11-30'])->assertCreated();

        $pending = fn (string $q = '') => $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/v1/credit/promises'.$q)->assertOk()->json('data');
        $this->assertCount(2, $pending());
        $this->assertCount(1, $pending('?horizon_days=7'));
        $this->assertCount(0, $pending('?status=kept'));
    }

    public function test_a_seller_only_sees_promises_of_their_customers(): void
    {
        $this->promise()->assertCreated();
        $this->promise(['code' => 'C2'])->assertCreated();

        $this->assertSame(['C1'], array_column(array_column($this->rows($this->vendedor), 'customer'), 'code'));
        $this->assertCount(2, $this->rows());
        $this->assertCount(1, $this->rows(null, '&company_id='.$this->a->id.'&code=C2'));
    }
}
