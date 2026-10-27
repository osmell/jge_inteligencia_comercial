<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Automations\DigestDailyHandler;
use App\Automations\DigestWeeklyHandler;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\Digest;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Quotation;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\DigestBuilder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DigestsTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $branch;

    private User $admin;

    private User $manager;

    private Seller $seller;

    private Customer $customer;

    private int $docs = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 08:00:00');                       // miércoles
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->a = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        $this->b = Company::factory()->create(['code' => 'B', 'name' => 'Beta']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->manager->companies()->attach($this->a->id);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->manager->role_id, ['permissions' => ['digests.view']])->assertOk();
        Auth::forgetGuards();
        $this->seller = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera']);
        $this->customer = Customer::create(['company_id' => $this->a->id, 'code' => 'C1', 'name' => 'Cliente Uno', 'source' => 'sales', 'seller_id' => $this->seller->id]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function sale(string $on, float $net, string $customer = 'C1', string $product = 'P1'): void
    {
        $operator = Operator::firstOrCreate(['company_id' => $this->a->id, 'normalized_name' => 'caja'], ['name' => 'Caja']);
        $batch = ImportBatch::query()->value('id') ?? ImportBatch::factory()->create(['type' => 'sales', 'user_id' => $this->admin->id])->id;
        $docId = DB::table('sales_documents')->insertGetId([
            'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'F', 'number' => (string) ++$this->docs, 'issued_on' => $on, 'issued_at' => null, 'customer_code' => $customer,
            'customer_name' => "Cliente $customer", 'seller_id' => $this->seller->id, 'operator_id' => $operator->id, 'import_batch_id' => $batch, 'lines_count' => 1, 'content_hash' => 'h'.$this->docs, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('sales_lines')->insert([
            'sales_document_id' => $docId, 'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'issued_on' => $on, 'line_number' => 1, 'product_code' => $product, 'product_name' => "Producto $product", 'category_name' => 'C',
            'units' => 1, 'unit_cost' => 1, 'line_cost_total' => 1, 'unit_price' => $net, 'normal_price' => $net, 'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function seedBusiness(): void
    {
        foreach ([['2026-10-20', 700], ['2026-10-20', 500], ['2026-10-13', 1000], ['2026-10-06', 800], ['2026-09-22', 1000]] as [$day, $net]) {   // el martes 29-sep no vendió
            $this->sale($day, $net);
        }
        $this->sale('2026-10-15', 300, 'C2', 'P2');
        $operator = Operator::first();
        foreach ([['1', '2026-09-11', 1000], ['2', '2026-07-12', 500]] as [$n, $issued, $total]) {                     // 40 y 101 días
            $i = CreditInvoice::create(['company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'A', 'number' => $n, 'issued_on' => $issued, 'customer_code' => 'C1', 'customer_name' => 'Cliente Uno', 'seller_id' => $this->seller->id, 'operator_id' => $operator->id, 'subtotal' => $total, 'discount_total' => 0, 'tax_total' => 0, 'total' => $total, 'content_hash' => $n]);
            CreditMovement::create(['company_id' => $this->a->id, 'credit_invoice_id' => $i->id, 'type' => 'invoice', 'amount' => $total, 'occurred_on' => $issued, 'created_at' => now()]);
        }
        $quote = fn (int $n, string $until) => Quotation::create(['company_id' => $this->a->id, 'customer_id' => $this->customer->id, 'sequence' => $n, 'number' => sprintf('COT-%06d', $n), 'status' => 'sent', 'amount' => 300, 'valid_until' => $until]);
        $quote(1, '2026-10-23');
        $quote(2, '2026-10-10');
        $quote(3, '2026-12-01');
        CustomerInteraction::create(['company_id' => $this->a->id, 'customer_id' => $this->customer->id, 'user_id' => $this->admin->id, 'channel' => 'call', 'direction' => 'outbound', 'outcome' => 'spoke', 'occurred_at' => now()->subDays(8), 'next_action' => 'Llamar', 'next_action_at' => '2026-10-19 10:00:00']);
    }

    /** @return array<string, array<string, mixed>> */
    private function metrics(Digest $d, string $section): array
    {
        $s = collect($d->data['sections'])->firstWhere('key', $section);

        return collect($s['metrics'])->keyBy('label')->all();
    }

    public function test_the_daily_digest_compares_yesterday_with_the_same_weekday_average_and_summarizes_the_company(): void
    {
        $this->seedBusiness();
        $d = app(DigestBuilder::class)->daily($this->a);

        $this->assertSame(['daily', '2026-10-20', 'Resumen diario de Alfa: martes 20/10/2026'], [$d->kind, $d->period_key, $d->title]);
        $this->assertSame(['sales', 'credit', 'quotations', 'followup', 'inventory'], array_column($d->data['sections'], 'key'));

        $sales = $this->metrics($d, 'sales');
        $day = $sales['Venta neta del martes 20/10'];
        $this->assertEquals([1200, 'money', 'good'], [$day['value'], $day['format'], $day['tone']]);
        $this->assertStringContainsString('promedio de los 3 martess anteriores con ventas (933.33)', $day['hint']);      // 1000, 800 y 1000: el 29-sep no vendió
        $this->assertStringContainsString('+28.6 %', $day['hint']);
        $this->assertEquals(2, $sales['Facturas del día']['value']);

        $credit = $this->metrics($d, 'credit');
        $this->assertEquals([1500, 1500, 500], [$credit['Saldo por cobrar']['value'], $credit['Saldo vencido (con 1 día o más)']['value'], $credit['Saldo con más de 90 días']['value']]);
        $this->assertSame('2 facturas con saldo', $credit['Saldo por cobrar']['hint']);

        $quotes = $this->metrics($d, 'quotations');
        $this->assertEquals([2, 1, 1], [$quotes['Cotizaciones abiertas']['value'], $quotes['Por vencer en 3 días']['value'], $quotes['Vencidas sin cerrar']['value']]);
        $this->assertEquals(1, $this->metrics($d, 'followup')['Acciones vencidas de toda la compañía']['value']);
    }

    public function test_a_digest_is_generated_once_per_period_and_keeps_its_figures(): void
    {
        $this->seedBusiness();
        $first = app(DigestBuilder::class)->daily($this->a);
        $this->sale('2026-10-20', 9999);                                         // un dato posterior no cambia el resumen ya generado

        $second = app(DigestBuilder::class)->daily($this->a);

        $this->assertSame($first->id, $second->id);
        $this->assertEquals(1200, $this->metrics($second, 'sales')['Venta neta del martes 20/10']['value']);
        $this->assertSame(1, Digest::count());
        $this->expectException(\LogicException::class);
        $second->update(['title' => 'x']);
    }

    public function test_the_weekly_digest_covers_the_previous_week_with_top_customers_and_products(): void
    {
        $this->seedBusiness();
        $this->sale('2026-10-13', 4000, 'C3', 'P3');                              // semana del 12 al 18 de octubre
        $d = app(DigestBuilder::class)->weekly($this->a);

        $this->assertSame(['weekly', '2026-W42', '2026-10-12', '2026-10-18'], [$d->kind, $d->period_key, $d->period_from->toDateString(), $d->period_to->toDateString()]);
        $this->assertSame('Resumen semanal de Alfa: 12/10 al 18/10/2026', $d->title);
        $sales = collect($d->data['sections'])->firstWhere('key', 'sales');
        $this->assertEquals(5300, $sales['metrics'][0]['value']);                  // 1000 + 300 + 4000 (las ventas del 6 y del 20 de octubre son de otras semanas)
        $this->assertSame(['Cliente C3', 'Cliente C1', 'Cliente C2'], array_column($sales['tables'][0]['rows'], 'name'));
        $this->assertSame(['Producto P3', 'Producto P1', 'Producto P2'], array_column($sales['tables'][1]['rows'], 'name'));
        $this->assertContains('insights', array_column($d->data['sections'], 'key'));
        $this->assertNotContains('goals', array_column($d->data['sections'], 'key'));       // sin metas capturadas, la sección no aparece
    }

    public function test_the_automations_generate_the_digests_and_notify_managers_with_full_access_once(): void
    {
        $this->seedBusiness();
        $branchOnly = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $branchOnly->companies()->attach($this->a->id);
        $branchOnly->branches()->attach($this->branch->id);                           // acceso restringido a una sucursal

        $result = app(DigestDailyHandler::class)->run([]);

        $this->assertSame(2, Digest::count());                                         // una por compañía activa
        $notice = UserNotification::where('user_id', $this->manager->id)->where('type', 'digest')->sole();
        $this->assertSame('Resumen diario de Alfa: martes 20/10/2026', $notice->title);
        $this->assertStringContainsString('Venta neta del martes 20/10: 1,200.00', (string) $notice->body);
        $this->assertSame('/resumenes/'.Digest::where('company_id', $this->a->id)->value('id'), $notice->url);
        $this->assertCount(2, UserNotification::where('user_id', $this->admin->id)->get());     // el administrador ve las dos compañías
        $this->assertSame(0, UserNotification::where('user_id', $branchOnly->id)->count());       // restringido a una sucursal: no ve cifras de toda la compañía
        $this->assertSame(['recipients' => 2, 'notices' => 3], $result);

        $this->assertSame(0, app(DigestDailyHandler::class)->run([])['notices']);              // se ejecuta de nuevo: nada nuevo
        app(DigestWeeklyHandler::class)->run([]);
        $this->assertSame(4, Digest::count());
    }

    public function test_the_api_only_shows_digests_of_companies_the_person_fully_sees(): void
    {
        $this->seedBusiness();
        app(DigestBuilder::class)->daily($this->a);
        $other = app(DigestBuilder::class)->daily($this->b);
        $branchOnly = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $branchOnly->companies()->attach($this->a->id);
        $branchOnly->branches()->attach($this->branch->id);

        $list = $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/digests')->assertOk();
        $this->assertSame(['Alfa'], array_column($list->json('meta.companies'), 'name'));
        $this->assertCount(1, $list->json('data'));
        $id = $list->json('data.0.id');
        $detail = $this->getJson("/api/v1/digests/$id")->assertOk();
        $this->assertSame('sales', $detail->json('data.sections.0.key'));
        $this->getJson("/api/v1/digests/{$other->id}")->assertNotFound();
        $this->getJson('/api/v1/digests?kind=weekly')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/digests?kind=inventado')->assertUnprocessable();

        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/digests')->assertJsonCount(2, 'data');
        $this->actingAs($branchOnly, 'sanctum')->getJson('/api/v1/digests')->assertOk()->assertJsonCount(0, 'data');      // restringido a una sucursal: ninguna compañía completa
        $this->actingAs(User::factory()->role(Role::VENDEDOR)->create(), 'sanctum')->getJson('/api/v1/digests')->assertForbidden();   // sin el permiso
    }

    public function test_generating_a_digest_by_hand_needs_the_automations_permission(): void
    {
        $this->seedBusiness();
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/digests/generate', ['company_id' => $this->a->id, 'kind' => 'daily'])->assertForbidden();

        $r = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/digests/generate', ['company_id' => $this->a->id, 'kind' => 'weekly'])->assertCreated();
        $this->assertSame(['weekly', '2026-W42'], [$r->json('data.kind'), $r->json('data.period_key')]);
        $this->postJson('/api/v1/digests/generate', ['company_id' => $this->a->id, 'kind' => 'weekly'])->assertCreated();
        $this->assertSame(1, Digest::count());
        $this->postJson('/api/v1/digests/generate', ['company_id' => $this->a->id, 'kind' => 'mensual'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['event' => 'digests.generated']);
    }
}
