<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\Operator;
use App\Models\PaymentPromise;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CreditScoreTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $branch;

    private User $admin;

    private Seller $seller;

    private Operator $operator;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->seller = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera']);
        $this->operator = Operator::create(['company_id' => $this->a->id, 'name' => 'Caja', 'normalized_name' => 'caja']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function invoice(string $number, string $issued, float $total, string $customer = 'C1'): CreditInvoice
    {
        $i = CreditInvoice::create([
            'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'A', 'number' => $number, 'issued_on' => $issued, 'customer_code' => $customer, 'customer_name' => "Cliente $customer",
            'seller_id' => $this->seller->id, 'operator_id' => $this->operator->id, 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'total' => $total, 'content_hash' => $number,
        ]);
        $this->movement($i, 'invoice', $total, $issued);

        return $i;
    }

    private function movement(CreditInvoice $i, string $type, float $amount, string $on): CreditMovement
    {
        return CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => $i->id, 'type' => $type, 'amount' => $amount, 'occurred_on' => $on, 'created_at' => now()]);
    }

    private function score(string $code = 'C1'): array
    {
        return $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/credit/score?company_id='.$this->a->id.'&code='.$code)->assertOk()->json('data');
    }

    private function factor(array $score, string $key): array
    {
        return collect($score['factors'])->firstWhere('key', $key);
    }

    public function test_a_customer_without_data_has_no_score(): void
    {
        $s = $this->score('NADIE');

        $this->assertNull($s['score']);
        $this->assertNull($s['band']);
        $this->assertSame([null, null, null, null], array_column($s['factors'], 'score'));
        $this->assertStringContainsString('No aprueba ni modifica límites de crédito', $s['disclaimer']);
    }

    public function test_punctuality_and_aging_combine_with_redistributed_weights(): void
    {
        $a = $this->invoice('1', '2026-08-01', 1000);
        $this->movement($a, 'payment', -1000, '2026-08-20');             // a tiempo
        $b = $this->invoice('2', '2026-08-10', 1000);
        $this->movement($b, 'payment', -500, '2026-08-30');              // a tiempo
        // quedan 500 sin saldar tras 71 días
        $this->invoice('3', '2026-10-10', 500);                          // aún no cumple 30 días

        $s = $this->score();

        $punctuality = $this->factor($s, 'punctuality');
        $this->assertSame(80, $punctuality['score']);                   // 500 atrasados de 2500 facturados
        $this->assertSame(40, $punctuality['weight']);
        // Saldo abierto: 500 a 71 días (tramo 61-90) y 500 a 10 días (tramo 1-30) → castigo (0,6·500 + 0,2·500)/1000
        $this->assertSame(60, $this->factor($s, 'aging')['score']);
        $this->assertNull($this->factor($s, 'promises')['score']);
        $this->assertSame(0.0, (float) $this->factor($s, 'promises')['effective_weight']);
        $this->assertSame([61.5, 38.5], [(float) $punctuality['effective_weight'], (float) $this->factor($s, 'aging')['effective_weight']]);
        $this->assertSame(72, $s['score']);
        $this->assertSame('medium', $s['band']);
    }

    public function test_a_customer_that_paid_everything_on_time_scores_high(): void
    {
        $i = $this->invoice('1', '2026-09-01', 1000);
        $this->movement($i, 'payment', -1000, '2026-09-15');

        $s = $this->score();
        $this->assertSame([100, 100, 100, 'low'], [$s['score'], $this->factor($s, 'punctuality')['score'], $this->factor($s, 'aging')['score'], $s['band']]);
    }

    public function test_reversed_payments_do_not_count_as_settled(): void
    {
        $i = $this->invoice('1', '2026-08-01', 1000);
        $paid = $this->movement($i, 'payment', -1000, '2026-08-15');
        $this->movement($i, 'reversal', 1000, '2026-08-16')->forceFill(['reverses_id' => $paid->id])->saveQuietly();

        $s = $this->score();
        $this->assertSame(0, $this->factor($s, 'punctuality')['score']);
        $this->assertSame('high', $s['band']);
    }

    public function test_promises_count_only_resolved_ones(): void
    {
        $i = $this->invoice('1', '2026-09-20', 1000);
        $promise = fn (string $made, string $due) => PaymentPromise::create([
            'company_id' => $this->a->id, 'customer_code' => 'C1', 'customer_name' => 'Cliente C1', 'made_on' => $made, 'promised_on' => $due, 'amount' => 100, 'user_id' => $this->admin->id,
        ]);
        $promise('2026-10-01', '2026-10-05');
        $this->movement($i, 'payment', -100, '2026-10-03');              // cumple la primera
        $promise('2026-10-06', '2026-10-10');                             // incumplida
        $promise('2026-10-19', '2026-10-30');                             // pendiente: no cuenta
        $cancelled = $promise('2026-10-01', '2026-10-02');
        $cancelled->forceFill(['cancelled_at' => now()])->save();         // cancelada: no cuenta

        $f = $this->factor($this->score(), 'promises');
        $this->assertSame(50, $f['score']);
        $this->assertSame('1 cumplidas y 1 incumplidas.', $f['detail']);
    }

    public function test_it_requires_the_credit_view_permission(): void
    {
        $other = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->actingAs($other, 'sanctum')->getJson('/api/v1/credit/score?company_id=1&code=C1')->assertForbidden();
    }
}
