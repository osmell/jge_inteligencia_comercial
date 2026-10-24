<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSalesFixtures;
use Tests\TestCase;

/** Reporte de evaluación de operadores: producción y control de captura, contra ventas declaradas a mano. */
class OperatorReportTest extends TestCase
{
    use BuildsSalesFixtures, RefreshDatabase;

    private Company $alfa;

    private Company $beta;

    private Branch $centro;

    private Branch $norte;

    private Seller $seller;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['name' => 'Alfa']);
        $this->beta = Company::factory()->create(['name' => 'Beta']);
        $this->centro = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Centro']);
        $this->norte = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Norte']);
        $this->seller = $this->seller($this->alfa, 'Ana');
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->manager->companies()->sync([$this->alfa->id]);
    }

    /** @return array<string, mixed> */
    private function report(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->manager, 'sanctum')->getJson('/api/v1/reports/operators?'.http_build_query($query + ['from' => '2026-10-01', 'to' => '2026-10-31']))->assertOk()->json('data');
    }

    /** @return array<string, mixed> */
    private function rowOf(array $report, Operator $operator): array
    {
        foreach ($report['rows'] as $row) {
            if ($row['operator']['id'] === $operator->id) {
                return $row;
            }
        }
        $this->fail("El reporte no trae a {$operator->name}.");
    }

    public function test_production_figures_and_growth(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $olga = $this->operator($this->alfa, 'Olga');
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [['net' => 600.0], ['net' => 400.0]]);
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C2', [['net' => 500.0]]);
        $this->sale($this->seller, $omar, $this->centro, '2026-10-20', 'C1', [['net' => 100.0]]);
        $this->sale($this->seller, $olga, $this->norte, '2026-10-10', 'C3', [['net' => 500.0]]);
        $this->sale($this->seller, $omar, $this->centro, '2026-09-15', 'C1', [['net' => 1000.0]]);   // periodo anterior

        $report = $this->report();
        $row = $this->rowOf($report, $omar);

        $this->assertEquals(1600.0, $row['sales']['net_total']);
        $this->assertSame(3, $row['sales']['documents']);
        $this->assertSame(4, $row['production']['lines']);
        $this->assertEquals(1.33, $row['production']['lines_per_document']);
        $this->assertSame(2, $row['production']['active_days']);
        $this->assertEquals(1.5, $row['production']['documents_per_active_day']);
        $this->assertEquals(60.0, $row['sales']['growth_pct']);
        $this->assertSame(1, $row['sales']['previous_documents']);
        $this->assertEquals(200.0, $row['sales']['documents_growth_pct']);
        $this->assertSame(1, $row['rank']);
        $this->assertSame(2, $this->rowOf($report, $olga)['rank']);
        $this->assertEquals(2100.0, $report['totals']['net_total']);
        $this->assertSame(2, $report['totals']['operators']);
    }

    public function test_high_discount_and_free_lines_are_counted_exactly(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [
            ['net' => 80.0, 'subtotal' => 100.0, 'discount' => 20.0],     // 20 % exacto: cuenta
            ['net' => 81.0, 'subtotal' => 100.0, 'discount' => 19.0],     // 19 %: no cuenta
            ['net' => 100.0, 'subtotal' => 100.0, 'discount' => 0.0],
            ['net' => 0.0, 'price' => 0.0],                                // regalada: sin precio
            ['net' => -50.0],                                              // devolución: no es «sin precio»
        ]);

        $row = $this->rowOf($this->report(), $omar);

        $this->assertSame(1, $row['control']['high_discount_lines']);
        $this->assertEquals(20.0, $row['control']['high_discount_lines_pct']);   // 1 de 5 líneas
        $this->assertSame(1, $row['control']['zero_price_lines']);
        $this->assertEquals(20.0, $row['control']['zero_price_lines_pct']);
        $this->assertSame('bad', $row['semaphores']['high_discount_lines_pct']);
        $this->assertSame('bad', $row['semaphores']['zero_price_lines_pct']);
    }

    public function test_atypical_documents_are_flagged_against_the_company_norm(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $olga = $this->operator($this->alfa, 'Olga');
        for ($i = 0; $i < 24; $i++) {
            $this->sale($this->seller, $i % 2 === 0 ? $omar : $olga, $this->centro, '2026-10-05', 'C'.$i, [['net' => 100.0 + $i]]);
        }
        $this->sale($this->seller, $omar, $this->centro, '2026-10-06', 'GRANDE', [['net' => 10000.0]]);

        $report = $this->report();

        $this->assertSame(1, $this->rowOf($report, $omar)['control']['atypical_documents']);
        $this->assertSame(0, $this->rowOf($report, $olga)['control']['atypical_documents']);
        $this->assertEquals(round(1 / 13 * 100, 2), $this->rowOf($report, $omar)['control']['atypical_documents_pct']);   // 13 documentos de Omar
        $this->assertSame(1, $report['totals']['atypical_documents']);
    }

    public function test_with_few_documents_nothing_is_called_atypical(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        foreach ([100.0, 110.0, 105.0, 9999.0] as $i => $net) {
            $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C'.$i, [['net' => $net]]);
        }

        $row = $this->rowOf($this->report(), $omar);

        $this->assertSame(0, $row['control']['atypical_documents']);
        $this->assertNull($row['control']['atypical_documents_pct'], 'Sin base suficiente no se califica.');
        $this->assertSame('none', $row['semaphores']['atypical_documents_pct']);
    }

    public function test_negative_documents_are_counted(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [['net' => 100.0]]);
        $this->sale($this->seller, $omar, $this->centro, '2026-10-06', 'C1', [['net' => -30.0]]);

        $this->assertSame(1, $this->rowOf($this->report(), $omar)['control']['negative_documents']);
    }

    public function test_direct_sales_are_those_where_the_seller_and_the_operator_are_the_same_person(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $sellerOmar = $this->seller($this->alfa, 'Omar');
        $this->sale($sellerOmar, $omar, $this->centro, '2026-10-05', 'C1', [['net' => 300.0]]);
        $this->sale($this->seller, $omar, $this->centro, '2026-10-06', 'C2', [['net' => 700.0]]);

        $this->assertEquals(30.0, $this->rowOf($this->report(), $omar)['control']['direct_sales_pct']);
    }

    public function test_price_discipline_uses_the_same_definitions_as_the_seller_report(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [
            ['net' => 90.0, 'normal' => 100.0, 'price' => 90.0, 'subtotal' => 100.0, 'discount' => 10.0],
            ['net' => 300.0, 'normal' => 100.0, 'price' => 100.0, 'subtotal' => 300.0, 'discount' => 0.0],
        ]);

        $sales = $this->rowOf($this->report(), $omar)['sales'];

        $this->assertEquals(round(90 / 390 * 100, 2), $sales['below_list_pct']);
        $this->assertEquals(2.5, $sales['discount_pct']);
    }

    public function test_the_consolidated_sales_match_the_sum_of_the_operators(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $olga = $this->operator($this->alfa, 'Olga');
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [['net' => 600.0], ['net' => 400.0]]);
        $this->sale($this->seller, $olga, $this->norte, '2026-10-10', 'C1', [['net' => 250.5]]);

        $reconciliation = $this->report()['reconciliation'];

        $this->assertTrue($reconciliation['matches']);
        $this->assertEquals(1250.5, $reconciliation['rows_net']);
        $this->assertSame(2, $reconciliation['rows_documents']);
    }

    public function test_filtering_by_branch_keeps_only_operators_who_billed_there(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $olga = $this->operator($this->alfa, 'Olga');
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [['net' => 100.0]]);
        $this->sale($this->seller, $olga, $this->norte, '2026-10-10', 'C1', [['net' => 250.0]]);

        $report = $this->report(['branch_id' => $this->norte->id]);

        $this->assertSame(['Olga'], array_column(array_column($report['rows'], 'operator'), 'name'));
        $this->assertTrue($report['reconciliation']['matches']);
    }

    public function test_the_report_warns_that_cancellations_by_operator_are_not_known(): void
    {
        $this->operator($this->alfa, 'Omar');

        $this->assertContains('no_operator_cancellations', $this->report()['notes']);
    }

    public function test_the_billing_detail_and_the_distribution_of_an_operator(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $luis = $this->seller($this->alfa, 'Luis');
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [['net' => 600.0]], '2026-10-05 09:15:00');   // lunes
        $this->sale($this->seller, $omar, $this->norte, '2026-10-06', 'C2', [['net' => 200.0]], '2026-10-06 09:45:00');    // martes
        $this->sale($luis, $omar, $this->norte, '2026-10-10', 'C3', [['net' => 200.0]], '2026-10-10 15:00:00');             // sábado
        $this->sale($luis, $omar, $this->norte, '2026-10-12', 'C3', [['net' => 0.0]]);                                       // sin hora
        $this->sale($luis, $this->operator($this->alfa, 'Otro'), $this->norte, '2026-10-12', 'C4', [['net' => 999.0]]);    // de otro operador

        $detail = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/operators/{$omar->id}/documents?from=2026-10-01&to=2026-10-31")->assertOk()->json('data');
        $this->assertSame('Omar', $detail['subject']['name']);
        $this->assertSame(4, $detail['totals']['documents']);
        $this->assertEquals(1000.0, $detail['totals']['net_total']);
        $this->assertEqualsCanonicalizing(['Ana', 'Luis'], array_values(array_unique(array_column($detail['rows'], 'seller'))));

        $dist = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/operators/{$omar->id}/distribution?from=2026-10-01&to=2026-10-31")->assertOk()->json('data');
        $this->assertSame(['Ana', 'Luis'], array_column($dist['sellers'], 'name'));
        $this->assertEquals([80.0, 20.0], array_column($dist['sellers'], 'share_pct'));
        $this->assertSame(['Centro', 'Norte'], array_column($dist['branches'], 'name'));
        $this->assertEquals([60.0, 40.0], array_column($dist['branches'], 'share_pct'));
        $this->assertSame(4, $dist['documents']);
        $this->assertSame(3, $dist['timed_documents']);
        $this->assertSame(2, $dist['by_hour'][9]);
        $this->assertSame(1, $dist['by_hour'][15]);
        $this->assertSame([2, 1, 0, 0, 0, 1, 0], $dist['by_weekday'], 'Lunes 5 y 12 (este sin hora), martes 6 y sábado 10.');
        $this->assertCount(4, $dist['daily']);
    }

    public function test_the_distribution_has_no_hours_when_the_files_never_carried_them(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [['net' => 100.0]]);

        $dist = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/operators/{$omar->id}/distribution?from=2026-10-01&to=2026-10-31")->json('data');

        $this->assertNull($dist['by_hour']);
        $this->assertSame(0, $dist['timed_documents']);
    }

    public function test_a_manager_never_sees_another_company(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $olga = $this->operator($this->beta, 'Olga');
        $beto = $this->seller($this->beta, 'Beto');
        $branchBeta = Branch::factory()->create(['company_id' => $this->beta->id]);
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [['net' => 100.0]]);
        $this->sale($beto, $olga, $branchBeta, '2026-10-05', 'C1', [['net' => 9999.0]]);

        $report = $this->report();

        $this->assertSame(['Omar'], array_column(array_column($report['rows'], 'operator'), 'name'));
        $this->assertEquals(100.0, $report['totals']['net_total']);
        $this->assertSame([], $this->report(['company_id' => $this->beta->id])['rows']);
        foreach (['documents', 'distribution'] as $detail) {
            $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/operators/{$olga->id}/$detail?from=2026-10-01&to=2026-10-31")->assertNotFound();
        }
    }

    public function test_a_supervisor_limited_to_one_branch_still_sees_the_whole_billing_of_every_operator(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $this->sale($this->seller, $omar, $this->centro, '2026-10-05', 'C1', [['net' => 600.0]]);
        $this->sale($this->seller, $omar, $this->norte, '2026-10-06', 'C2', [['net' => 400.0]]);
        $supervisor = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $supervisor->companies()->sync([$this->alfa->id]);
        $supervisor->branches()->sync([$this->centro->id]);

        $report = $this->report(as: $supervisor);

        $this->assertEquals(1000.0, $this->rowOf($report, $omar)['sales']['net_total']);
        $this->assertTrue($report['reconciliation']['matches']);
    }

    public function test_sellers_cannot_open_the_operator_report(): void
    {
        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $seller->companies()->sync([$this->alfa->id]);
        $omar = $this->operator($this->alfa, 'Omar');

        foreach (['', "/{$omar->id}/documents", "/{$omar->id}/distribution"] as $path) {
            $this->actingAs($seller, 'sanctum')->getJson("/api/v1/reports/operators$path?from=2026-10-01&to=2026-10-31")->assertForbidden();
        }
    }

    public function test_opening_the_report_and_its_details_is_audited(): void
    {
        $omar = $this->operator($this->alfa, 'Omar');
        $this->report(['company_id' => $this->alfa->id]);
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/operators/{$omar->id}/distribution?from=2026-10-01&to=2026-10-31");

        $this->assertSame(['operators', 'operator_distribution'], AuditLog::where('event', 'report.viewed')->orderBy('id')->get()->map(fn ($l) => $l->new_values['report'])->all());
    }

    public function test_invalid_queries_are_rejected(): void
    {
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/operators')->assertStatus(422)->assertJsonValidationErrors('from');
        $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/operators?from=2026-10-31&to=2026-10-01')->assertStatus(422)->assertJsonValidationErrors('to');
    }

    public function test_an_inactive_operator_without_billing_is_left_out_but_one_with_billing_stays(): void
    {
        $gone = $this->operator($this->alfa, 'Baja');
        $gone->update(['is_active' => false]);
        $quiet = $this->operator($this->alfa, 'Inactivo sin ventas');
        $quiet->update(['is_active' => false]);
        $this->sale($this->seller, $gone, $this->centro, '2026-10-05', 'C1', [['net' => 10.0]]);

        $names = array_column(array_column($this->report()['rows'], 'operator'), 'name');

        $this->assertContains('Baja', $names);
        $this->assertNotContains('Inactivo sin ventas', $names);
    }
}
