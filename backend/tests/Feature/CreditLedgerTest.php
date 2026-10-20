<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\CreditLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;
use Tests\TestCase;

class CreditLedgerTest extends TestCase
{
    use RefreshDatabase;

    private CreditLedger $ledger;

    private CreditInvoice $invoice;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->user = User::factory()->role(Role::ADMINISTRADOR)->create();
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $seller = Seller::create(['company_id' => $company->id, 'name' => 'Vera', 'normalized_name' => 'vera']);
        $operator = Operator::create(['company_id' => $company->id, 'name' => 'Caja', 'normalized_name' => 'caja']);
        $this->invoice = CreditInvoice::create([
            'company_id' => $company->id, 'branch_id' => $branch->id, 'series' => 'A', 'number' => '100', 'issued_on' => '2026-10-01', 'customer_code' => 'C1', 'customer_name' => 'Uno',
            'seller_id' => $seller->id, 'operator_id' => $operator->id, 'subtotal' => 1000, 'discount_total' => 0, 'tax_total' => 150, 'total' => 1150, 'content_hash' => 'x',
        ]);
        CreditMovement::create(['company_id' => $company->id, 'credit_invoice_id' => $this->invoice->id, 'type' => 'invoice', 'amount' => 1150, 'occurred_on' => '2026-10-01', 'created_at' => now()]);
        $this->ledger = app(CreditLedger::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function pay(string $amount, string $on = '2026-10-10'): CreditMovement
    {
        return $this->ledger->record($this->invoice, CreditMovement::PAYMENT, $amount, Carbon::parse($on), $this->user, ['method' => 'transferencia', 'reference' => 'T-1']);
    }

    private function fails(callable $action, string $field): void
    {
        try {
            $action();
            $this->fail('Se esperaba un error de validación en '.$field);
        } catch (ValidationException $e) {
            $this->assertArrayHasKey($field, $e->errors());
        }
    }

    public function test_the_balance_is_the_sum_of_the_movements(): void
    {
        $this->assertSame('1150.0000', $this->ledger->balance($this->invoice));

        $this->pay('400.50');
        $this->ledger->record($this->invoice, CreditMovement::CREDIT_NOTE, '49.50', Carbon::parse('2026-10-12'), $this->user, ['note' => 'Devolución parcial']);
        $this->ledger->record($this->invoice, CreditMovement::ADJUSTMENT, '10', Carbon::parse('2026-10-13'), $this->user, ['note' => 'Cargo por mora']);

        $this->assertSame('710.0000', $this->ledger->balance($this->invoice));
        $this->assertSame('710.0000', number_format((float) DB::table('credit_movements')->sum('amount'), 4, '.', ''));
        $this->assertSame(['1150.0000', '-400.5000', '-49.5000', '10.0000'], CreditMovement::orderBy('id')->pluck('amount')->all());
    }

    public function test_a_payment_can_settle_the_invoice_but_not_exceed_it(): void
    {
        $this->pay('1000');
        $this->fails(fn () => $this->pay('150.01'), 'amount');
        $this->assertSame('150.0000', $this->ledger->balance($this->invoice));

        $this->pay('150');
        $this->assertSame('0.0000', $this->ledger->balance($this->invoice));
        $this->fails(fn () => $this->pay('0.01'), 'amount');
    }

    public function test_amounts_dates_and_notes_are_validated(): void
    {
        $this->fails(fn () => $this->pay('0'), 'amount');
        $this->fails(fn () => $this->pay('-5'), 'amount');
        $this->fails(fn () => $this->pay('10', '2026-09-30'), 'occurred_on');
        $this->fails(fn () => $this->pay('10', '2026-10-21'), 'occurred_on');
        $this->fails(fn () => $this->ledger->record($this->invoice, CreditMovement::ADJUSTMENT, '5', Carbon::parse('2026-10-10'), $this->user), 'note');
        $this->fails(fn () => $this->ledger->record($this->invoice, CreditMovement::ADJUSTMENT, '-2000', Carbon::parse('2026-10-10'), $this->user, ['note' => 'x']), 'amount');
        $this->fails(fn () => $this->ledger->record($this->invoice, CreditMovement::INVOICE, '5', Carbon::parse('2026-10-10'), $this->user), 'type');
        $this->assertSame(1, CreditMovement::count());
    }

    public function test_a_negative_adjustment_reduces_the_balance(): void
    {
        $this->ledger->record($this->invoice, CreditMovement::ADJUSTMENT, '-50', Carbon::parse('2026-10-10'), $this->user, ['note' => 'Descuento autorizado']);

        $this->assertSame('1100.0000', $this->ledger->balance($this->invoice));
    }

    public function test_a_movement_is_reversed_with_a_linked_opposite_entry_and_never_deleted(): void
    {
        $payment = $this->pay('400');
        $reversal = $this->ledger->reverse($payment, $this->user, 'Cheque rebotado');

        $this->assertSame('1150.0000', $this->ledger->balance($this->invoice));
        $this->assertSame([CreditMovement::REVERSAL, '400.0000', $payment->id, 'Cheque rebotado'], [$reversal->type, $reversal->amount, $reversal->reverses_id, $reversal->note]);
        $this->assertSame('-400.0000', $payment->fresh()->amount);
        $this->assertSame(3, CreditMovement::count());

        $this->fails(fn () => $this->ledger->reverse($payment, $this->user, 'otra vez'), 'movement');
        $this->fails(fn () => $this->ledger->reverse($reversal, $this->user, 'no'), 'movement');
        $this->fails(fn () => $this->ledger->reverse(CreditMovement::where('type', 'invoice')->sole(), $this->user, 'no'), 'movement');
        $this->fails(fn () => $this->ledger->reverse($this->pay('10'), $this->user, ' '), 'note');
    }

    public function test_a_reversal_cannot_leave_a_negative_balance(): void
    {
        $adjustment = $this->ledger->record($this->invoice, CreditMovement::ADJUSTMENT, '100', Carbon::parse('2026-10-10'), $this->user, ['note' => 'Cargo']);
        $this->pay('1250');

        $this->fails(fn () => $this->ledger->reverse($adjustment, $this->user, 'Error'), 'amount');
        $this->assertSame('0.0000', $this->ledger->balance($this->invoice));
    }

    public function test_every_movement_is_audited_with_who_and_what(): void
    {
        $this->pay('400');
        $audit = AuditLog::where('event', 'credit.movement_payment')->sole();

        $this->assertSame($this->user->id, $audit->user_id);
        $this->assertSame('-400.0000', $audit->new_values['amount']);
        $this->assertSame('transferencia', $audit->new_values['method']);
    }

    public function test_movements_cannot_be_edited_or_deleted(): void
    {
        $payment = $this->pay('400');

        try {
            $payment->update(['amount' => '-1']);
            $this->fail('Un movimiento no debe poder modificarse.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('inmutable', $e->getMessage());
        }
        $this->expectException(LogicException::class);
        $payment->delete();
    }
}
