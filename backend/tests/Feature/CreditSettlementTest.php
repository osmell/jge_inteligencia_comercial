<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\CreditNote;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CreditSettlementTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private User $admin;

    private User $supervisor;

    private User $vendedor;

    private Seller $vera;

    private Seller $otro;

    private CreditInvoice $old;

    private CreditInvoice $new;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $this->vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        foreach ([$this->supervisor, $this->vendedor] as $u) {
            $u->companies()->attach($this->a->id);
        }
        $branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $operator = Operator::create(['company_id' => $this->a->id, 'name' => 'Caja', 'normalized_name' => 'caja']);
        $this->vera = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->vendedor->id]);
        $this->otro = Seller::create(['company_id' => $this->a->id, 'name' => 'Otro', 'normalized_name' => 'otro']);

        $make = fn (string $number, string $issued, float $total, string $customer = 'C1', ?Seller $seller = null) => tap(CreditInvoice::create([
            'company_id' => $this->a->id, 'branch_id' => $branch->id, 'series' => 'A', 'number' => $number, 'issued_on' => $issued, 'customer_code' => $customer, 'customer_name' => "Cliente $customer",
            'seller_id' => ($seller ?? $this->vera)->id, 'operator_id' => $operator->id, 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'total' => $total, 'content_hash' => $number,
        ]), fn ($i) => CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => $i->id, 'type' => 'invoice', 'amount' => $total, 'occurred_on' => $issued, 'created_at' => now()]));
        $this->old = $make('1', '2026-09-01', 1000);
        $this->new = $make('2', '2026-10-01', 500);

        // Abonos: credit.payments; notas de crédito: credit.notes (supervisor); el vendedor solo consulta.
        $this->actingAs($this->admin, 'sanctum');
        $this->putJson('/api/v1/admin/role-permissions/'.$this->supervisor->role_id, ['permissions' => ['credit.view', 'credit.payments', 'credit.notes']])->assertOk();
        $this->putJson('/api/v1/admin/role-permissions/'.$this->vendedor->role_id, ['permissions' => ['credit.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function balance(CreditInvoice $i): float
    {
        return (float) CreditMovement::where('credit_invoice_id', $i->id)->sum('amount');
    }

    private function payment(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->supervisor, 'sanctum')->postJson('/api/v1/credit/payments', $over + [
            'company_id' => $this->a->id, 'code' => 'C1', 'occurred_on' => '2026-10-15', 'method' => 'transferencia', 'reference' => 'T-9',
            'allocations' => [['invoice_id' => $this->old->id, 'amount' => '400'], ['invoice_id' => $this->new->id, 'amount' => '100.50']],
        ]);
    }

    private function note(array $over = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->supervisor, 'sanctum')->postJson('/api/v1/credit/credit-notes', $over + [
            'company_id' => $this->a->id, 'code' => 'C1', 'occurred_on' => '2026-10-16', 'series' => 'NC', 'number' => '77', 'subtotal' => '200', 'tax_total' => '30', 'total' => '230',
            'observations' => 'Devolución parcial', 'allocations' => [['invoice_id' => $this->old->id, 'amount' => '130'], ['invoice_id' => $this->new->id, 'amount' => '100']],
        ]);
    }

    public function test_a_payment_is_split_across_invoices_of_the_customer(): void
    {
        $this->payment()->assertCreated()->assertJsonPath('data.movements', 2);

        $this->assertSame(600.0, $this->balance($this->old));
        $this->assertSame(399.5, $this->balance($this->new));
        $this->assertDatabaseHas('credit_movements', ['credit_invoice_id' => $this->old->id, 'type' => 'payment', 'method' => 'transferencia', 'reference' => 'T-9', 'user_id' => $this->supervisor->id]);
    }

    public function test_a_payment_is_all_or_nothing_and_cannot_overpay(): void
    {
        $this->payment(['allocations' => [['invoice_id' => $this->old->id, 'amount' => '400'], ['invoice_id' => $this->new->id, 'amount' => '600']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['allocations.1.amount']);

        $this->assertSame(1000.0, $this->balance($this->old));
        $this->assertSame(500.0, $this->balance($this->new));
    }

    public function test_payment_validations(): void
    {
        $this->payment(['occurred_on' => '2026-10-25'])->assertUnprocessable()->assertJsonValidationErrors(['occurred_on']);
        $this->payment(['occurred_on' => '2026-08-01'])->assertUnprocessable()->assertJsonValidationErrors(['occurred_on']);
        $this->payment(['method' => ''])->assertUnprocessable()->assertJsonValidationErrors(['method']);
        $this->payment(['allocations' => [['invoice_id' => $this->old->id, 'amount' => '0']]])->assertUnprocessable();
        $this->payment(['allocations' => [['invoice_id' => $this->old->id, 'amount' => '5'], ['invoice_id' => $this->old->id, 'amount' => '5']]])->assertUnprocessable()->assertJsonValidationErrors(['allocations']);
        $this->payment(['code' => 'OTRO'])->assertUnprocessable()->assertJsonValidationErrors(['allocations.0.invoice_id']);
        $this->assertSame(0, CreditMovement::where('type', 'payment')->count());
    }

    public function test_only_users_with_the_matching_permission_can_register(): void
    {
        $this->payment(as: $this->vendedor)->assertForbidden();
        $this->note(as: $this->vendedor)->assertForbidden();
        $this->actingAs($this->vendedor, 'sanctum')->getJson('/api/v1/credit/open-invoices?company_id='.$this->a->id.'&code=C1')->assertForbidden();

        // El permiso de abonos no autoriza las notas de crédito.
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->supervisor->role_id, ['permissions' => ['credit.view', 'credit.payments']])->assertOk();
        $this->note()->assertForbidden();
        $this->payment()->assertCreated();
    }

    public function test_open_invoices_list_the_oldest_first_with_balance(): void
    {
        $this->payment(['allocations' => [['invoice_id' => $this->new->id, 'amount' => '500']]])->assertCreated();

        $rows = $this->actingAs($this->supervisor, 'sanctum')->getJson('/api/v1/credit/open-invoices?company_id='.$this->a->id.'&code=C1')->assertOk()->json('data');
        $this->assertSame(['A-1'], array_column($rows, 'document'));
        $this->assertSame('1000.0000', $rows[0]['balance']);
    }

    public function test_a_credit_note_is_stored_and_reduces_the_balances(): void
    {
        $this->note()->assertCreated()->assertJsonPath('data.document', 'NC-77');

        $this->assertSame(870.0, $this->balance($this->old));
        $this->assertSame(400.0, $this->balance($this->new));
        $note = CreditNote::sole();
        $this->assertSame(['200.0000', '30.0000', '230.0000', 'Cliente C1'], [$note->subtotal, $note->tax_total, $note->total, $note->customer_name]);
        $this->assertSame(2, CreditMovement::where('credit_note_id', $note->id)->where('type', 'credit_note')->count());
        $this->assertDatabaseHas('audit_logs', ['event' => 'credit.note_created']);
    }

    public function test_a_credit_note_must_add_up_and_cannot_leave_an_excess(): void
    {
        $this->note(['total' => '240'])->assertUnprocessable()->assertJsonValidationErrors(['total']);
        $this->note(['allocations' => [['invoice_id' => $this->old->id, 'amount' => '100']]])->assertUnprocessable()->assertJsonValidationErrors(['allocations']);
        $this->note(['allocations' => [['invoice_id' => $this->new->id, 'amount' => '230']], 'total' => '230'])->assertCreated();
        $this->assertSame(1, CreditNote::count());
    }

    public function test_a_credit_note_document_is_unique_and_a_failure_leaves_nothing(): void
    {
        $this->note()->assertCreated();
        $this->note(['allocations' => [['invoice_id' => $this->old->id, 'amount' => '230']]])->assertUnprocessable()->assertJsonValidationErrors(['number']);
        $this->assertSame(1, CreditNote::count());

        // Si una parte supera el saldo, no queda ni la nota.
        $this->note(['number' => '78', 'subtotal' => '1000', 'tax_total' => '0', 'total' => '1000', 'allocations' => [['invoice_id' => $this->new->id, 'amount' => '1000']]])
            ->assertUnprocessable()->assertJsonValidationErrors(['allocations.0.amount']);
        $this->assertSame(1, CreditNote::count());
    }

    public function test_reversing_a_payment_restores_the_balance_and_needs_the_permission(): void
    {
        $this->payment()->assertCreated();
        $id = CreditMovement::where('type', 'payment')->where('credit_invoice_id', $this->old->id)->value('id');

        $this->actingAs($this->vendedor, 'sanctum')->postJson("/api/v1/credit/movements/$id/reverse", ['note' => 'x'])->assertForbidden();
        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/movements/$id/reverse", [])->assertUnprocessable();
        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/movements/$id/reverse", ['note' => 'Error de captura'])->assertOk();
        $this->assertSame(1000.0, $this->balance($this->old));
        $this->assertSame(399.5, $this->balance($this->new));
        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/movements/$id/reverse", ['note' => 'otra vez'])->assertUnprocessable();
    }

    public function test_reversing_a_credit_note_reverses_every_invoice_it_touched(): void
    {
        $this->note()->assertCreated();
        $id = CreditMovement::where('type', 'credit_note')->orderBy('id')->value('id');

        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/movements/$id/reverse", ['note' => 'Nota mal capturada'])->assertOk();
        $this->assertSame(1000.0, $this->balance($this->old));
        $this->assertSame(500.0, $this->balance($this->new));
        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/movements/$id/reverse", ['note' => 'otra vez'])->assertUnprocessable();
    }

    public function test_adjustments_and_invoices_cannot_be_reversed_from_the_screen(): void
    {
        $inv = CreditMovement::where('type', 'invoice')->value('id');
        $this->actingAs($this->supervisor, 'sanctum')->postJson("/api/v1/credit/movements/$inv/reverse", ['note' => 'x'])->assertUnprocessable();
    }
}
