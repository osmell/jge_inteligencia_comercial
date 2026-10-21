<?php

namespace Tests\Feature;

use App\Automations\CreditBucketHandler;
use App\Automations\FollowupDueHandler;
use App\Automations\InsightHighHandler;
use App\Automations\InventoryLowHandler;
use App\Automations\PromiseDueHandler;
use App\Automations\QuotationExpiringHandler;
use App\Models\AutomationRun;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\InsightAlert;
use App\Models\InsightRun;
use App\Models\InventoryMovement;
use App\Models\Operator;
use App\Models\PaymentPromise;
use App\Models\Product;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Seller;
use App\Models\Unit;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\AutomationRunner;
use App\Services\InsightRunLogger;
use App\Services\InventoryLedger;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class OperationalNoticesTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $branch;

    private User $admin;

    private User $seller;

    private Seller $mine;

    private Seller $other;

    private Customer $c1;

    private Customer $c2;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 08:00:00');                       // miércoles
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->seller = User::factory()->role(Role::VENDEDOR)->create();
        $this->a = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Central']);
        $this->seller->companies()->attach($this->a->id);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->seller->role_id, ['permissions' => ['interactions.manage', 'customers.view', 'credit.view', 'inventory.view', 'insights.view']])->assertOk();
        Auth::forgetGuards();
        $this->mine = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->seller->id]);
        $this->other = Seller::create(['company_id' => $this->a->id, 'name' => 'Otro', 'normalized_name' => 'otro']);
        $this->c1 = Customer::create(['company_id' => $this->a->id, 'code' => 'C1', 'name' => 'Cliente Uno', 'source' => 'sales', 'seller_id' => $this->mine->id]);
        $this->c2 = Customer::create(['company_id' => $this->a->id, 'code' => 'C2', 'name' => 'Cliente Dos', 'source' => 'sales', 'seller_id' => $this->other->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    /** @return Collection<int, UserNotification> */
    private function notices(User $user, ?string $type = null): Collection
    {
        return UserNotification::where('user_id', $user->id)->when($type, fn ($q) => $q->where('type', $type))->orderBy('id')->get();
    }

    private function interaction(Customer $c, string $nextAt, User $by): void
    {
        CustomerInteraction::create(['company_id' => $this->a->id, 'customer_id' => $c->id, 'user_id' => $by->id, 'channel' => 'call', 'direction' => 'outbound', 'outcome' => 'spoke', 'occurred_at' => now()->subDays(5), 'next_action' => 'Llamar', 'next_action_at' => $nextAt]);
    }

    private function invoice(string $number, string $issued, float $total, Seller $seller, string $customer = 'C1'): CreditInvoice
    {
        $operator = Operator::firstOrCreate(['company_id' => $this->a->id, 'normalized_name' => 'caja'], ['name' => 'Caja']);
        $i = CreditInvoice::create([
            'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'A', 'number' => $number, 'issued_on' => $issued, 'customer_code' => $customer, 'customer_name' => "Cliente $customer",
            'seller_id' => $seller->id, 'operator_id' => $operator->id, 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'total' => $total, 'content_hash' => $number,
        ]);
        CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => $i->id, 'type' => 'invoice', 'amount' => $total, 'occurred_on' => $issued, 'created_at' => now()]);

        return $i;
    }

    public function test_followup_summary_counts_overdue_and_today_within_each_persons_scope(): void
    {
        $this->interaction($this->c1, '2026-10-20 10:00:00', $this->seller);        // vencida
        $this->interaction($this->c1, '2026-10-21 15:00:00', $this->seller);        // para hoy
        $this->interaction($this->c2, '2026-10-20 09:00:00', $this->seller);        // de un cliente que el vendedor no ve
        $this->interaction($this->c1, '2026-10-25 09:00:00', $this->seller);        // futura: no cuenta

        $result = app(FollowupDueHandler::class)->run([]);

        $mine = $this->notices($this->seller, 'followup_due')->sole();
        $this->assertSame(['Tienes 1 acción vencida y 1 acción para hoy en tu agenda', 'warning', '/agenda', 'followup_due:2026-10-21'], [$mine->title, $mine->severity, $mine->url, $mine->dedupe_key]);
        $this->assertStringContainsString('2 acciones vencidas y 1 acción para hoy', $this->notices($this->admin, 'followup_due')->sole()->title);      // el administrador ve todo
        $this->assertSame(['recipients' => 2, 'notices' => 2], $result);

        $again = app(FollowupDueHandler::class)->run([]);                                    // reintento: nada nuevo
        $this->assertSame(['recipients' => 0, 'notices' => 0], $again);
        $this->assertSame(1, $this->notices($this->seller, 'followup_due')->count());
    }

    public function test_followup_summary_respects_preferences_and_skips_people_with_nothing_due(): void
    {
        $this->interaction($this->c1, '2026-10-21 15:00:00', $this->seller);
        $this->actingAs($this->seller, 'sanctum')->putJson('/api/v1/notifications/preferences', ['types' => ['followup_due' => false]])->assertOk();

        app(FollowupDueHandler::class)->run([]);

        $this->assertCount(0, $this->notices($this->seller));
        $this->assertSame('Tienes 1 acción para hoy en tu agenda', $this->notices($this->admin, 'followup_due')->sole()->title);
        $this->assertSame('info', $this->notices($this->admin, 'followup_due')->sole()->severity);
    }

    public function test_quotations_about_to_expire_or_expired_notify_the_owner_once_and_only_if_they_see_the_customer(): void
    {
        $make = function (int $n, Customer $c, string $validUntil, string $status = 'sent') {
            return Quotation::create(['company_id' => $this->a->id, 'customer_id' => $c->id, 'user_id' => $this->seller->id, 'seller_id' => $c->seller_id, 'sequence' => $n, 'number' => sprintf('COT-%06d', $n), 'status' => $status, 'amount' => 1500, 'valid_until' => $validUntil]);
        };
        $soon = $make(1, $this->c1, '2026-10-23');          // vence en 2 días
        $today = $make(2, $this->c1, '2026-10-21');
        $expired = $make(3, $this->c1, '2026-10-20');
        $make(4, $this->c1, '2026-11-15');                  // lejos
        $make(5, $this->c1, '2026-10-22', 'accepted');       // aceptada: no vence
        $make(6, $this->c2, '2026-10-22');                  // de un cliente que el vendedor no ve

        app(QuotationExpiringHandler::class)->run(['days_before' => 3]);

        $titles = $this->notices($this->seller, 'quotation_expiring')->pluck('title', 'dedupe_key');
        $this->assertSame([
            "quotation:{$soon->id}:expiring" => 'La cotización COT-000001 vence en 2 días',
            "quotation:{$today->id}:expiring" => 'La cotización COT-000002 vence hoy',
            "quotation:{$expired->id}:expired" => 'La cotización COT-000003 venció',
        ], $titles->all());
        $this->assertSame('danger', $this->notices($this->seller)->firstWhere('dedupe_key', "quotation:{$expired->id}:expired")->severity);
        $this->assertSame("/cotizaciones/{$soon->id}", $this->notices($this->seller)->first()->url);

        app(QuotationExpiringHandler::class)->run(['days_before' => 3]);                    // sin duplicados
        $this->assertSame(3, $this->notices($this->seller, 'quotation_expiring')->count());

        $narrow = app(QuotationExpiringHandler::class)->run(['days_before' => 0]);           // con menos anticipación, la de 2 días ya estaba avisada
        $this->assertSame(0, $narrow['notices']);
    }

    public function test_promises_due_today_and_broken_ones_notify_their_owner_and_the_invoice_seller(): void
    {
        $this->invoice('1', '2026-09-01', 1000, $this->mine);
        $promise = fn (string $made, string $due, float $amount) => PaymentPromise::create(['company_id' => $this->a->id, 'customer_code' => 'C1', 'customer_name' => 'Cliente Uno', 'made_on' => $made, 'promised_on' => $due, 'amount' => $amount, 'user_id' => $this->admin->id]);
        $due = $promise('2026-10-15', '2026-10-21', 300);
        $broken = $promise('2026-10-10', '2026-10-19', 200);
        $kept = $promise('2026-10-10', '2026-10-20', 100);
        CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => CreditInvoice::first()->id, 'type' => 'payment', 'amount' => -100, 'occurred_on' => '2026-10-20', 'created_at' => now()]);
        $future = $promise('2026-10-15', '2026-10-30', 50);

        app(PromiseDueHandler::class)->run([]);

        $seller = $this->notices($this->seller, 'promise_due')->pluck('title', 'dedupe_key');
        $this->assertEquals(["promise:{$due->id}:due" => 'La promesa de pago de Cliente Uno vence hoy', "promise:{$broken->id}:broken" => 'Promesa de pago incumplida: Cliente Uno'], $seller->all());
        $this->assertCount(2, $this->notices($this->admin, 'promise_due'));                  // quien la registró
        $this->assertSame('danger', $this->notices($this->seller)->firstWhere('dedupe_key', "promise:{$broken->id}:broken")->severity);
        $this->assertNull($this->notices($this->seller)->firstWhere('dedupe_key', "promise:{$kept->id}:due"));
        $this->assertNull($this->notices($this->seller)->firstWhere('dedupe_key', "promise:{$future->id}:due"));

        $this->assertSame(0, app(PromiseDueHandler::class)->run([])['notices']);
    }

    public function test_invoices_that_crossed_a_bucket_are_summarized_per_person_and_respect_the_minimum_balance(): void
    {
        $this->invoice('1', '2026-09-20', 1000, $this->mine);                // 31 días: pasó de 1-30 a 31-60
        $this->invoice('2', '2026-10-11', 500, $this->mine);                 // 10 días: no cruzó
        $this->invoice('3', '2026-09-20', 200, $this->other, 'C2');          // cruzó, pero es de otro vendedor
        $this->invoice('4', '2026-09-20', 30, $this->mine);                  // cruzó, saldo pequeño

        app(CreditBucketHandler::class)->run(['min_balance' => 100]);

        $mine = $this->notices($this->seller, 'credit_bucket')->sole();
        $this->assertSame('1 factura pasó a otro tramo de cartera', $mine->title);
        $this->assertStringContainsString('A-1 (31 a 60 días)', (string) $mine->body);
        $this->assertStringContainsString('Saldo 1.000,00', (string) $mine->body);
        $this->assertStringNotContainsString('A-3', (string) $mine->body);
        $this->assertSame('2 facturas pasaron a otro tramo de cartera', $this->notices($this->admin, 'credit_bucket')->sole()->title);

        $this->assertSame(0, app(CreditBucketHandler::class)->run(['min_balance' => 100])['notices']);       // el mismo resumen no se repite
        app(CreditBucketHandler::class)->run(['min_balance' => 0]);                                          // otro conjunto de facturas sí es otro aviso
        $this->assertSame(2, $this->notices($this->seller, 'credit_bucket')->count());
    }

    public function test_inventory_summary_lists_the_most_urgent_products_for_people_who_see_inventory(): void
    {
        $product = Product::create(['company_id' => $this->a->id, 'code' => 'P1', 'name' => 'Aceite', 'base_unit_id' => Unit::where('code', 'galon')->value('id')]);
        $ledger = app(InventoryLedger::class);
        $ledger->record($this->a->id, $this->branch->id, $product, InventoryMovement::OPENING, '5', null, Carbon::parse('2026-09-01'), $this->admin, '50');
        $ledger->record($this->a->id, $this->branch->id, $product, InventoryMovement::SALE, '5', null, Carbon::parse('2026-10-01'), null);       // agotado con demanda

        app(InventoryLowHandler::class)->run([]);

        $n = $this->notices($this->seller, 'inventory_low')->sole();
        $this->assertSame(['1 producto agotado', 'danger', '/inventario/rotacion'], [$n->title, $n->severity, $n->url]);
        $this->assertStringContainsString('Aceite (Central)', (string) $n->body);
        $this->assertSame(1, $this->notices($this->admin, 'inventory_low')->count());
        $this->assertSame(0, app(InventoryLowHandler::class)->run([])['notices']);
    }

    public function test_only_new_serious_recommendations_visible_to_each_person_are_summarized(): void
    {
        $run = app(InsightRunLogger::class)->record($this->a->id, 'inactivity', InsightRun::OK, null, null);
        $alert = fn (string $code, string $severity, string $detected) => InsightAlert::create([
            'company_id' => $this->a->id, 'insight_run_id' => $run->id, 'customer_code' => $code, 'customer_name' => "Cliente $code", 'type' => 'inactivity_risk', 'severity' => $severity, 'dedupe_key' => "k:$code:$severity:$detected",
            'facts' => [], 'explanation' => 'x', 'status' => 'open', 'first_detected_at' => $detected, 'last_detected_at' => $detected,
        ]);
        $alert('C1', 'high', '2026-10-21 02:00:00');
        $alert('C2', 'high', '2026-10-21 02:00:00');       // cliente de otro vendedor
        $alert('C1', 'medium', '2026-10-21 02:00:00');     // no es grave
        $alert('C1', 'high', '2026-10-10 02:00:00');       // ya no es nueva

        app(InsightHighHandler::class)->run([]);

        $this->assertSame(['1 recomendación grave nueva', '/inteligencia/alertas'], [$this->notices($this->seller, 'insight_high')->sole()->title, $this->notices($this->seller, 'insight_high')->sole()->url]);
        $this->assertSame('2 recomendaciones graves nuevas', $this->notices($this->admin, 'insight_high')->sole()->title);
    }

    public function test_the_scheduler_runs_the_real_operational_automations_inside_the_window(): void
    {
        $this->interaction($this->c1, '2026-10-21 15:00:00', $this->seller);
        $runner = app(AutomationRunner::class);

        $this->assertSame(3, $runner->tick(CarbonImmutable::parse('2026-10-25 08:00:00'))['started']);                  // domingo: solo el mantenimiento y los envíos al webhook
        $this->assertCount(0, $this->notices($this->seller));

        $r = $runner->tick(CarbonImmutable::parse('2026-10-21 08:00:00'));
        $this->assertGreaterThanOrEqual(6, $r['started']);
        $this->assertSame(['ok'], AutomationRun::whereIn('automation', ['followup_due', 'quotation_expiring', 'promise_due', 'credit_bucket', 'inventory_low', 'insight_high'])->pluck('status')->unique()->all());
        $this->assertCount(1, $this->notices($this->seller, 'followup_due'));
    }
}
