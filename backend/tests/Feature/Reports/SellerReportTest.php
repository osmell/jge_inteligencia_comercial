<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\SellerGoal;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\BuildsSalesFixtures;
use Tests\TestCase;

/** Reporte de evaluación de vendedores: cada cifra se comprueba contra ventas declaradas a mano. */
class SellerReportTest extends TestCase
{
    use BuildsSalesFixtures, RefreshDatabase;

    private Company $alfa;

    private Company $beta;

    private Branch $centro;

    private Branch $norte;

    private Operator $operator;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-10-25 12:00:00');   // las cifras «a la fecha» (cartera, crédito, pendientes) dependen de hoy
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['name' => 'Alfa']);
        $this->beta = Company::factory()->create(['name' => 'Beta']);
        $this->centro = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Centro']);
        $this->norte = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Norte']);
        $this->operator = $this->operator($this->alfa, 'Omar');
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->manager->companies()->sync([$this->alfa->id]);
    }

    /** @return array<string, mixed> */
    private function report(array $query = [], ?User $as = null): array
    {
        $response = $this->actingAs($as ?? $this->manager, 'sanctum')->getJson('/api/v1/reports/sellers?'.http_build_query($query + ['from' => '2026-10-01', 'to' => '2026-10-31']));

        return $response->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function rowOf(array $report, Seller $seller): array
    {
        foreach ($report['rows'] as $row) {
            if ($row['seller']['id'] === $seller->id) {
                return $row;
            }
        }
        $this->fail("El reporte no trae a {$seller->name}.");
    }

    public function test_sales_figures_are_exact_and_compared_with_the_previous_period(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $luis = $this->seller($this->alfa, 'Luis');
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0], ['net' => 400.0]]);
        $this->sale($ana, $this->operator, $this->centro, '2026-10-20', 'C2', [['net' => 500.0]]);
        $this->sale($luis, $this->operator, $this->norte, '2026-10-10', 'C1', [['net' => 500.0]]);
        // Periodo anterior (1–30 de septiembre → comparación automática: 31 días antes del 1 de octubre).
        $this->sale($ana, $this->operator, $this->centro, '2026-09-15', 'C1', [['net' => 1000.0]]);

        $report = $this->report();
        $row = $this->rowOf($report, $ana);

        $this->assertEquals(1500.0, $row['sales']['net_total']);
        $this->assertSame(2, $row['sales']['documents']);
        $this->assertSame(2, $row['sales']['customers']);
        $this->assertEquals(750.0, $row['sales']['average_ticket']);
        $this->assertEquals(1000.0, $row['sales']['previous_net_total']);
        $this->assertEquals(50.0, $row['sales']['growth_pct']);
        $this->assertEquals(75.0, $row['sales']['share_pct']);
        $this->assertSame(1, $row['rank']);
        $this->assertSame(2, $this->rowOf($report, $luis)['rank']);
        $this->assertNull($this->rowOf($report, $luis)['sales']['growth_pct'], 'Sin ventas en el periodo anterior no hay crecimiento que calcular.');
        $this->assertEquals(2000.0, $report['totals']['net_total']);
        $this->assertSame(3, $report['totals']['documents']);
        $this->assertSame(2, $report['totals']['sellers']);
    }

    public function test_price_discipline_figures(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [
            ['net' => 90.0, 'normal' => 100.0, 'price' => 90.0, 'subtotal' => 100.0, 'discount' => 10.0],   // bajo el precio normal, con descuento
            ['net' => 300.0, 'normal' => 100.0, 'price' => 100.0, 'subtotal' => 300.0, 'discount' => 0.0],   // al precio normal
            ['net' => 110.0],                                                                                  // sin precio normal: no cuenta para el indicador
        ]);

        $sales = $this->rowOf($this->report(), $ana)['sales'];

        $this->assertSame(round(90 / 390 * 100, 2), $sales['below_list_pct']);   // 90 de los 390 con precio normal
        $this->assertEquals(2.5, $sales['discount_pct']);                          // 10 de descuento sobre 400 de subtotal
    }

    public function test_concentration_in_the_three_best_customers(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        foreach (['C1' => 400, 'C2' => 300, 'C3' => 200, 'C4' => 60, 'C5' => 40] as $code => $net) {
            $this->sale($ana, $this->operator, $this->centro, '2026-10-05', $code, [['net' => (float) $net]]);
        }

        $this->assertEquals(90.0, $this->rowOf($this->report(), $ana)['sales']['top3_concentration_pct']);   // 900 de 1000
    }

    public function test_variety_is_measured_in_categories_per_customer(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 10.0, 'category' => 'A'], ['net' => 10.0, 'category' => 'B']]);
        $this->sale($ana, $this->operator, $this->centro, '2026-10-06', 'C2', [['net' => 10.0, 'category' => 'A']]);

        $this->assertEquals(1.5, $this->rowOf($this->report(), $ana)['sales']['categories_per_customer']);   // 3 pares cliente-categoría / 2 clientes
    }

    public function test_goal_attainment_for_a_whole_month_and_its_semaphore(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $luis = $this->seller($this->alfa, 'Luis');
        SellerGoal::create(['seller_id' => $ana->id, 'month' => '2026-10-01', 'amount' => 1000]);
        SellerGoal::create(['seller_id' => $luis->id, 'month' => '2026-10-01', 'amount' => 1000]);
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 800.0]]);
        $this->sale($luis, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 1200.0]]);

        $report = $this->report();

        $this->assertEquals(80.0, $this->rowOf($report, $ana)['goal']['attainment_pct']);
        $this->assertSame('warn', $this->rowOf($report, $ana)['semaphores']['goal_attainment']);
        $this->assertSame('good', $this->rowOf($report, $luis)['semaphores']['goal_attainment']);
        $this->assertEquals(100.0, $report['totals']['attainment_pct']);   // 2000 de 2000
    }

    public function test_the_goal_is_prorated_for_a_partial_month_and_by_days_off(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        SellerGoal::create(['seller_id' => $ana->id, 'month' => '2026-10-01', 'amount' => 3100, 'days_off' => 0]);
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 500.0]]);

        // Del 1 al 10 de octubre (10 de 31 días): la meta del periodo es 3100 × 10/31 = 1000.
        $row = $this->rowOf($this->report(['to' => '2026-10-10']), $ana);

        $this->assertEquals(1000.0, $row['goal']['goal']);
        $this->assertEquals(50.0, $row['goal']['attainment_pct']);
        $this->assertSame('bad', $row['semaphores']['goal_attainment']);

        // Con 11 días de ausencia el mes tiene 20 días elegibles: la meta del mes es 3100 × 20/31 = 2000.
        SellerGoal::where('seller_id', $ana->id)->update(['days_off' => 11]);
        $this->assertEquals(2000.0, $this->rowOf($this->report(), $ana)['goal']['goal']);
    }

    public function test_a_period_with_a_month_missing_its_goal_is_not_rated(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        SellerGoal::create(['seller_id' => $ana->id, 'month' => '2026-10-01', 'amount' => 1000]);
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 800.0]]);
        $this->sale($ana, $this->operator, $this->centro, '2026-11-05', 'C1', [['net' => 800.0]]);

        // Octubre y noviembre; noviembre no tiene meta: no se califica en vez de inflar o desinflar el porcentaje.
        $row = $this->rowOf($this->report(['to' => '2026-11-30']), $ana);

        $this->assertNull($row['goal']['attainment_pct']);
        $this->assertSame('none', $row['semaphores']['goal_attainment']);
    }

    public function test_a_seller_without_a_goal_is_not_rated(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 800.0]]);

        $this->assertSame('none', $this->rowOf($this->report(), $ana)['semaphores']['goal_attainment']);
    }

    public function test_filtering_by_branch_does_not_compare_partial_sales_with_the_full_goal(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        SellerGoal::create(['seller_id' => $ana->id, 'month' => '2026-10-01', 'amount' => 1000]);
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0]]);
        $this->sale($ana, $this->operator, $this->norte, '2026-10-06', 'C2', [['net' => 400.0]]);

        $report = $this->report(['branch_id' => $this->norte->id]);

        $this->assertEquals(400.0, $this->rowOf($report, $ana)['sales']['net_total']);
        $this->assertNull($this->rowOf($report, $ana)['goal']['attainment_pct']);
        $this->assertContains('goal_not_comparable_with_branch_filter', $report['notes']);
    }

    public function test_the_portfolio_attributes_each_customer_to_the_seller_who_billed_them_last(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $luis = $this->seller($this->alfa, 'Luis');
        // Historial que empieza antes, para que «nuevo» tenga sentido.
        $this->sale($luis, $this->operator, $this->centro, '2026-01-02', 'C0', [['net' => 1.0]]);
        $this->sale($ana, $this->operator, $this->centro, '2026-10-20', 'ACTIVO', [['net' => 10.0]]);
        $this->sale($ana, $this->operator, $this->centro, '2026-05-01', 'PERDIDO', [['net' => 10.0]]);      // 5 meses sin comprar: perdido (> 90 días)
        $this->sale($ana, $this->operator, $this->centro, '2026-09-01', 'INACTIVO', [['net' => 10.0]]);     // ~50 días: inactivo (> 30, ≤ 90)
        $this->sale($luis, $this->operator, $this->centro, '2026-03-01', 'COMPARTIDO', [['net' => 10.0]]);
        $this->sale($ana, $this->operator, $this->centro, '2026-10-21', 'COMPARTIDO', [['net' => 10.0]]);   // el último que facturó fue Ana

        $portfolio = $this->rowOf($this->report(), $ana)['portfolio'];

        $this->assertSame(4, $portfolio['total']);
        $this->assertSame(1, $portfolio['lost']);
        $this->assertSame(1, $portfolio['inactive']);
        $this->assertEquals(25.0, $portfolio['lost_pct']);
        // A Luis solo le queda C0 (su último cliente sin otra factura posterior); COMPARTIDO pasó a Ana.
        $this->assertSame(1, $this->rowOf($this->report(), $luis)['portfolio']['total']);
    }

    public function test_credit_balance_and_overdue_share(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $this->invoice($ana, '2026-10-15', 600.0, paid: 0.0);       // vigente
        $this->invoice($ana, '2026-07-01', 1000.0, paid: 600.0);    // vencida: saldo 400
        $this->invoice($ana, '2026-06-01', 500.0, paid: 500.0);     // pagada: no cuenta

        $credit = $this->rowOf($this->report(), $ana)['credit'];

        $this->assertEquals(1000.0, $credit['balance']);
        $this->assertEquals(400.0, $credit['overdue']);
        $this->assertEquals(40.0, $credit['overdue_pct']);
    }

    private function invoice(Seller $seller, string $date, float $total, float $paid): void
    {
        $batch = DB::table('import_batches')->value('id') ?? ImportBatch::factory()->create()->id;
        $id = DB::table('credit_invoices')->insertGetId([
            'company_id' => $seller->company_id, 'branch_id' => $this->centro->id, 'series' => 'F', 'number' => (string) random_int(1, 999999), 'issued_on' => $date,
            'customer_code' => 'C1', 'customer_name' => 'Cliente', 'seller_id' => $seller->id, 'operator_id' => $this->operator->id, 'subtotal' => $total,
            'tax_total' => 0, 'total' => $total, 'import_batch_id' => $batch, 'content_hash' => hash('sha256', (string) random_int(1, PHP_INT_MAX)), 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('credit_movements')->insert(['company_id' => $seller->company_id, 'credit_invoice_id' => $id, 'type' => 'invoice', 'amount' => $total, 'occurred_on' => $date, 'created_at' => now()]);
        if ($paid > 0) {
            DB::table('credit_movements')->insert(['company_id' => $seller->company_id, 'credit_invoice_id' => $id, 'type' => 'payment', 'amount' => -$paid, 'occurred_on' => $date, 'created_at' => now()]);
        }
    }

    public function test_quotation_conversion_and_follow_up_of_the_linked_user(): void
    {
        $rep = User::factory()->role(Role::VENDEDOR)->create();
        $ana = $this->seller($this->alfa, 'Ana', userId: $rep->id);
        $luis = $this->seller($this->alfa, 'Luis');
        $customer = Customer::create(['company_id' => $this->alfa->id, 'code' => 'C1', 'name' => 'Cliente']);

        foreach (['converted', 'sent', 'rejected', 'converted'] as $i => $status) {
            DB::table('quotations')->insert(['company_id' => $this->alfa->id, 'customer_id' => $customer->id, 'seller_id' => $ana->id, 'sequence' => $i + 1, 'number' => 'Q'.$i, 'status' => $status, 'amount' => 100, 'issued_at' => '2026-10-10 10:00:00', 'created_at' => now(), 'updated_at' => now()]);
        }
        // Un borrador (sin emitir) y una cotización de otro mes no cuentan.
        DB::table('quotations')->insert(['company_id' => $this->alfa->id, 'customer_id' => $customer->id, 'seller_id' => $ana->id, 'sequence' => 9, 'number' => 'Q9', 'status' => 'draft', 'amount' => 100, 'created_at' => now(), 'updated_at' => now()]);

        $interaction = fn (array $extra) => DB::table('customer_interactions')->insert($extra + ['company_id' => $this->alfa->id, 'customer_id' => $customer->id, 'user_id' => $rep->id, 'channel' => 'phone', 'direction' => 'outbound', 'outcome' => 'answered', 'occurred_at' => '2026-10-12 09:00:00', 'created_at' => now(), 'updated_at' => now()]);
        $interaction(['next_action_at' => '2026-10-14 10:00:00', 'next_action_done_at' => '2026-10-14 08:00:00']);   // a tiempo
        $interaction(['next_action_at' => '2026-10-15 10:00:00', 'next_action_done_at' => '2026-10-20 08:00:00']);   // tarde
        $interaction(['next_action_at' => '2026-10-16 10:00:00']);                                                    // sin atender: vencida

        $report = $this->report();
        $follow = $this->rowOf($report, $ana)['follow_up'];

        $this->assertSame(4, $follow['quotes_issued']);
        $this->assertSame(2, $follow['quotes_converted']);
        $this->assertEquals(50.0, $follow['quote_conversion_pct']);
        $this->assertSame(3, $follow['contacts']);
        $this->assertSame(3, $follow['actions_due']);
        $this->assertEquals(33.3, $follow['actions_on_time_pct']);
        $this->assertSame(1, $follow['overdue_follow_ups']);
        // Sin usuario vinculado: sin dato, no cero.
        $noUser = $this->rowOf($report, $luis)['follow_up'];
        $this->assertNull($noUser['contacts']);
        $this->assertNull($noUser['overdue_follow_ups']);
        $this->assertSame('none', $this->rowOf($report, $luis)['semaphores']['overdue_follow_ups']);
    }

    public function test_the_consolidated_sales_match_the_sum_of_the_sellers(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $luis = $this->seller($this->alfa, 'Luis');
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0], ['net' => 400.0]]);
        $this->sale($luis, $this->operator, $this->norte, '2026-10-10', 'C1', [['net' => 250.5]]);

        $reconciliation = $this->report()['reconciliation'];

        $this->assertTrue($reconciliation['matches']);
        $this->assertEquals(1250.5, $reconciliation['consolidated_net']);
        $this->assertEquals(1250.5, $reconciliation['sellers_net']);
        $this->assertEquals(0.0, $reconciliation['net_difference']);
        $this->assertSame(2, $reconciliation['consolidated_documents']);
    }

    public function test_the_billing_detail_lists_every_document_and_adds_up_to_the_report(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0], ['net' => 400.0]]);
        $this->sale($ana, $this->operator, $this->norte, '2026-10-20', 'C2', [['net' => 500.0]]);
        $this->sale($ana, $this->operator, $this->norte, '2026-11-02', 'C2', [['net' => 999.0]]);   // fuera del periodo

        $detail = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/sellers/{$ana->id}/documents?from=2026-10-01&to=2026-10-31")->assertOk()->json('data');

        $this->assertSame(2, $detail['totals']['documents']);
        $this->assertEquals(1500.0, $detail['totals']['net_total']);
        $this->assertSame(['A-2', 'A-1'], array_column($detail['rows'], 'document'), 'Del más reciente al más antiguo.');
        $this->assertSame(['Norte', 'Centro'], array_column($detail['rows'], 'branch'));
        $this->assertSame([1, 2], array_column($detail['rows'], 'lines'));
        $this->assertSame($this->rowOf($this->report(), $ana)['sales']['net_total'], $detail['totals']['net_total']);
    }

    public function test_the_billing_detail_is_paginated(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        for ($i = 0; $i < 55; $i++) {
            $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 10.0]]);
        }

        $page = fn (int $n) => $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/sellers/{$ana->id}/documents?from=2026-10-01&to=2026-10-31&page=$n")->json('data');

        $this->assertCount(50, $page(1)['rows']);
        $this->assertCount(5, $page(2)['rows']);
        $this->assertSame(2, $page(1)['last_page']);
        $this->assertSame(55, $page(1)['totals']['documents']);
        $this->assertEquals(550.0, $page(2)['totals']['net_total'], 'Los totales son de todo el periodo, no de la página.');
    }

    public function test_a_manager_never_sees_another_company(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $beto = $this->seller($this->beta, 'Beto');
        $branchBeta = Branch::factory()->create(['company_id' => $this->beta->id]);
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 100.0]]);
        $this->sale($beto, $this->operator($this->beta, 'Olga'), $branchBeta, '2026-10-05', 'C1', [['net' => 9999.0]]);

        $report = $this->report();

        $this->assertSame(['Ana'], array_column(array_column($report['rows'], 'seller'), 'name'));
        $this->assertEquals(100.0, $report['totals']['net_total']);
        // Pedir la otra compañía a mano no funciona: no devuelve nada.
        $this->assertSame([], $this->report(['company_id' => $this->beta->id])['rows']);
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/sellers/{$beto->id}/documents?from=2026-10-01&to=2026-10-31")->assertNotFound();
    }

    public function test_a_supervisor_limited_to_one_branch_still_sees_the_whole_sales_of_every_seller_of_the_company(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $luis = $this->seller($this->alfa, 'Luis');
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 600.0]]);
        $this->sale($ana, $this->operator, $this->norte, '2026-10-06', 'C2', [['net' => 400.0]]);
        $this->sale($luis, $this->operator, $this->norte, '2026-10-07', 'C3', [['net' => 250.0]]);

        $supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $supervisor->companies()->sync([$this->alfa->id]);
        $supervisor->branches()->sync([$this->centro->id]);
        $report = $this->report(as: $supervisor);

        $this->assertEquals(1000.0, $this->rowOf($report, $ana)['sales']['net_total'], 'Evalúa al vendedor completo, no solo lo de su sucursal.');
        $this->assertEquals(250.0, $this->rowOf($report, $luis)['sales']['net_total']);
        $this->assertTrue($report['reconciliation']['matches']);
    }

    public function test_sellers_cannot_open_the_report_and_unauthenticated_calls_are_refused(): void
    {
        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $seller->companies()->sync([$this->alfa->id]);
        $ana = $this->seller($this->alfa, 'Ana');

        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/reports/sellers?from=2026-10-01&to=2026-10-31')->assertForbidden();
        $this->actingAs($seller, 'sanctum')->getJson("/api/v1/reports/sellers/{$ana->id}/documents?from=2026-10-01&to=2026-10-31")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/reports/sellers?from=2026-10-01&to=2026-10-31')->assertUnauthorized();
    }

    public function test_opening_the_report_is_audited_with_its_filters(): void
    {
        $this->seller($this->alfa, 'Ana');
        $this->report(['company_id' => $this->alfa->id]);

        $log = AuditLog::where('event', 'report.viewed')->firstOrFail();
        $this->assertSame($this->manager->id, $log->user_id);
        $this->assertSame('sellers', $log->new_values['report']);
        $this->assertSame($this->alfa->id, $log->company_id);
        $this->assertSame('2026-10-01', $log->new_values['filters']['from']);
    }

    /** @return array<string, array{array<string, string>, string}> */
    public static function badQueries(): array
    {
        return [
            'sin fechas' => [[], 'from'],
            'fin antes del inicio' => [['from' => '2026-10-31', 'to' => '2026-10-01'], 'to'],
            'periodo de más de 3 años' => [['from' => '2020-01-01', 'to' => '2026-10-01'], 'to'],
            'comparación desconocida' => [['from' => '2026-10-01', 'to' => '2026-10-31', 'compare' => 'otra'], 'compare'],
            'comparación personalizada sin fechas' => [['from' => '2026-10-01', 'to' => '2026-10-31', 'compare' => 'custom'], 'compare_from'],
            'fecha inválida' => [['from' => 'ayer', 'to' => '2026-10-31'], 'from'],
        ];
    }

    /** @param  array<string, string>  $query */
    #[DataProvider('badQueries')]
    public function test_invalid_queries_are_rejected(array $query, string $field): void
    {
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/sellers?'.http_build_query($query))->assertStatus(422)->assertJsonValidationErrors($field);
    }

    public function test_the_same_dates_of_last_year_can_be_the_comparison(): void
    {
        $ana = $this->seller($this->alfa, 'Ana');
        $this->sale($ana, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 300.0]]);
        $this->sale($ana, $this->operator, $this->centro, '2025-10-05', 'C1', [['net' => 200.0]]);

        $report = $this->report(['compare' => 'last_year']);

        $this->assertSame('2025-10-01', $report['comparison']['from']);
        $this->assertEquals(50.0, $this->rowOf($report, $ana)['sales']['growth_pct']);
    }

    public function test_an_inactive_seller_without_sales_is_left_out_but_one_with_sales_stays(): void
    {
        $gone = $this->seller($this->alfa, 'Baja');
        $gone->update(['is_active' => false]);
        $quiet = $this->seller($this->alfa, 'Inactivo sin ventas');
        $quiet->update(['is_active' => false]);
        $this->sale($gone, $this->operator, $this->centro, '2026-10-05', 'C1', [['net' => 10.0]]);

        $names = array_column(array_column($this->report()['rows'], 'seller'), 'name');

        $this->assertContains('Baja', $names);
        $this->assertNotContains('Inactivo sin ventas', $names);
    }
}
