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
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesFixtures;
use Tests\TestCase;

/** Mezcla de productos: qué se factura y qué tan amplio es el catálogo que mueve cada vendedor y operador, contra ventas declaradas a mano. */
class ProductMixReportTest extends TestCase
{
    use BuildsSalesFixtures, RefreshDatabase;

    private const PERIOD = ['from' => '2026-10-01', 'to' => '2026-10-31'];

    private Company $alfa;

    private Branch $centro;

    private Seller $ana;

    private Seller $beto;

    private Operator $omar;

    private Operator $olga;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->alfa = Company::factory()->create(['name' => 'Alfa']);
        $this->centro = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Centro']);
        $this->ana = $this->seller($this->alfa, 'Ana');
        $this->beto = $this->seller($this->alfa, 'Beto');
        $this->omar = $this->operator($this->alfa, 'Omar');
        $this->olga = $this->operator($this->alfa, 'Olga');
        $this->manager = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->manager->companies()->sync([$this->alfa->id]);

        // Catálogo: 4 categorías en 3 líneas y 2 grupos.
        foreach ([['P1', 'Taladro', 'Herramientas', 'Eléctricas', 'Taladros'], ['P2', 'Sierra', 'Herramientas', 'Eléctricas', 'Sierras'],
            ['P3', 'Martillo', 'Herramientas', 'Manuales', 'Martillos'], ['P4', 'Pintura', 'Hogar', 'Pinturas', 'Pintura interior']] as [$code, $name, $l1, $l2, $l3]) {
            DB::table('products')->insert(['company_id' => $this->alfa->id, 'code' => $code, 'name' => $name, 'level1' => $l1, 'level2' => $l2, 'level3' => $l3, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->sale($this->ana, $this->omar, $this->centro, '2026-10-05', 'C1', [['net' => 600.0, 'code' => 'P1', 'name' => 'Taladro'], ['net' => 200.0, 'code' => 'P2', 'name' => 'Sierra']]);
        $this->sale($this->ana, $this->omar, $this->centro, '2026-10-06', 'C2', [['net' => 100.0, 'code' => 'P3', 'name' => 'Martillo']]);
        $this->sale($this->beto, $this->omar, $this->centro, '2026-10-07', 'C4', [['net' => 100.0, 'code' => 'P1', 'name' => 'Taladro']]);
        $this->sale($this->beto, $this->olga, $this->centro, '2026-10-08', 'C3', [['net' => 50.0, 'code' => 'P4', 'name' => 'Pintura']]);
        $this->sale($this->ana, $this->omar, $this->centro, '2026-09-15', 'C1', [['net' => 350.0, 'code' => 'P1', 'name' => 'Taladro']]);
    }

    /** @return array<string, mixed> */
    private function report(array $query = [], ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->manager, 'sanctum')->getJson('/api/v1/reports/products?'.http_build_query(self::PERIOD + $query))->assertOk()->json('data');
    }

    public function test_ranking_by_product_goes_from_the_most_to_the_least_billed(): void
    {
        $ranking = $this->report(['level' => 'product'])['ranking'];

        $this->assertSame(['P1', 'P2', 'P3', 'P4'], array_column($ranking['items'], 'key'));
        $this->assertEquals([700, 200, 100, 50], array_column($ranking['items'], 'net_total'));
        $this->assertEquals([66.67, 19.05, 9.52, 4.76], array_column($ranking['items'], 'share_pct'));
        $this->assertSame([1, 2, 3, 4], array_column($ranking['items'], 'rank'));
        $this->assertEquals(1050, $ranking['net_total']);
        $this->assertSame(4, $ranking['total_items']);
        $this->assertFalse($ranking['truncated']);
        $this->assertSame(2, $ranking['items'][0]['customers']);
    }

    public function test_ranking_shows_growth_against_the_comparison_period(): void
    {
        $items = array_column($this->report(['level' => 'product'])['ranking']['items'], null, 'key');

        $this->assertEquals(350, $items['P1']['previous_net_total']);
        $this->assertEquals(100.0, $items['P1']['growth_pct']);
        $this->assertNull($items['P2']['growth_pct']);
    }

    public function test_every_level_groups_by_the_catalog_classification(): void
    {
        $this->assertEquals(['Taladros' => 700, 'Sierras' => 200, 'Martillos' => 100, 'Pintura interior' => 50], array_column($this->report(['level' => 'category'])['ranking']['items'], 'net_total', 'name'));
        $this->assertEquals(['Eléctricas' => 900, 'Manuales' => 100, 'Pinturas' => 50], array_column($this->report(['level' => 'line'])['ranking']['items'], 'net_total', 'name'));
        $this->assertEquals(['Herramientas' => 1000, 'Hogar' => 50], array_column($this->report(['level' => 'group'])['ranking']['items'], 'net_total', 'name'));
    }

    public function test_catalog_breadth_of_each_seller(): void
    {
        $data = $this->report();
        $rows = array_combine(array_column(array_column($data['rows'], 'subject'), 'name'), $data['rows']);

        $this->assertSame(['Ana', 'Beto'], array_keys($rows));
        $this->assertSame(1, $rows['Ana']['rank']);
        $this->assertEquals(900, $rows['Ana']['net_total']);
        $this->assertEquals([3, 3, 4, 75.0], [$rows['Ana']['products_sold'], $rows['Ana']['categories_sold'], $rows['Ana']['categories_catalog'], $rows['Ana']['category_coverage_pct']]);
        $this->assertSame([2, 3], [$rows['Ana']['lines_sold'], $rows['Ana']['lines_catalog']]);
        $this->assertEquals(66.7, $rows['Ana']['line_coverage_pct']);
        $this->assertEquals(100.0, $rows['Ana']['top5_share_pct']);
        $this->assertEquals([2, 50.0], [$rows['Beto']['categories_sold'], $rows['Beto']['category_coverage_pct']]);
        $this->assertSame(2, $data['rows'][1]['rank']);
        $this->assertEquals(62.5, $data['totals']['average_category_coverage_pct']);
        $this->assertSame([4, 3], [$data['totals']['catalog_categories'], $data['totals']['catalog_lines']]);
    }

    public function test_top_five_share_shows_dependence_on_few_products(): void
    {
        foreach (['P5', 'P6', 'P7', 'P8', 'P9'] as $i => $code) {
            $this->sale($this->beto, $this->omar, $this->centro, '2026-10-10', 'C9', [['net' => 10.0, 'code' => $code]]);
        }

        $rows = array_combine(array_column(array_column($this->report()['rows'], 'subject'), 'name'), $this->report()['rows']);

        // Beto: P1 100, P4 50 y cinco de 10 → sus 5 mayores son 100+50+10+10+10 = 180 de 200.
        $this->assertEquals(90.0, $rows['Beto']['top5_share_pct']);
        $this->assertSame(7, $rows['Beto']['products_sold']);
    }

    public function test_operators_are_evaluated_with_the_same_figures(): void
    {
        $data = $this->report(['subject' => 'operator']);
        $rows = array_combine(array_column(array_column($data['rows'], 'subject'), 'name'), $data['rows']);

        $this->assertSame('operator', $data['subject']);
        $this->assertEquals(1000, $rows['Omar']['net_total']);
        $this->assertSame(3, $rows['Omar']['categories_sold']);
        $this->assertEquals(50, $rows['Olga']['net_total']);
        $this->assertSame(1, $rows['Olga']['categories_sold']);
    }

    public function test_the_sum_of_people_matches_the_consolidated_sale(): void
    {
        $this->assertTrue($this->report()['reconciliation']['matches']);
        $this->assertTrue($this->report(['subject' => 'operator'])['reconciliation']['matches']);
    }

    public function test_seller_detail_shows_the_mix_index_the_gaps_and_the_missing_star_products(): void
    {
        $detail = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/products/sellers/{$this->beto->id}?".http_build_query(self::PERIOD + ['level' => 'category']))->assertOk()->json('data');

        $items = array_column($detail['ranking']['items'], null, 'name');
        $this->assertEquals(100, $items['Taladros']['mix_index']);
        $this->assertEquals(700, $items['Pintura interior']['mix_index']);
        $this->assertSame(['Sierras', 'Martillos'], array_column($detail['gaps']['categories'], 'name'));
        $this->assertEquals([200, 100], array_column($detail['gaps']['categories'], 'net_total'));
        $this->assertSame(['Manuales'], array_column($detail['gaps']['lines'], 'name'));
        $this->assertSame([4, 2], [$detail['star_products']['top'], $detail['star_products']['sold']]);
        $this->assertSame(['P2', 'P3'], array_column($detail['star_products']['missing'], 'key'));
        $this->assertSame('Beto', $detail['subject']['name']);
        $this->assertEquals(50.0, $detail['summary']['category_coverage_pct']);
    }

    public function test_a_return_does_not_count_as_selling_the_category(): void
    {
        $this->sale($this->beto, $this->omar, $this->centro, '2026-10-12', 'C1', [['net' => -30.0, 'code' => 'P2']]);

        $detail = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/products/sellers/{$this->beto->id}?".http_build_query(self::PERIOD + ['level' => 'category']))->assertOk()->json('data');

        $this->assertEquals(50.0, $detail['summary']['category_coverage_pct']);
        $this->assertContains('Sierras', array_column($detail['gaps']['categories'], 'name'));
    }

    public function test_operator_detail_compares_against_the_other_operators(): void
    {
        $detail = $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/reports/products/operators/{$this->olga->id}?".http_build_query(self::PERIOD + ['level' => 'line']))->assertOk()->json('data');

        $this->assertSame('operator', $detail['subject']['kind']);
        $this->assertSame(['Eléctricas', 'Manuales'], array_column($detail['gaps']['lines'], 'name'));
    }

    public function test_the_branch_filter_narrows_every_figure(): void
    {
        $norte = Branch::factory()->create(['company_id' => $this->alfa->id, 'name' => 'Norte']);
        $this->sale($this->ana, $this->omar, $norte, '2026-10-09', 'C5', [['net' => 400.0, 'code' => 'P4']]);

        $this->assertEquals(1450, $this->report()['ranking']['net_total']);
        $this->assertEquals(400, $this->report(['branch_id' => $norte->id])['ranking']['net_total']);
    }

    public function test_it_only_reads_the_company_of_the_person(): void
    {
        $beta = Company::factory()->create(['name' => 'Beta']);
        $branch = Branch::factory()->create(['company_id' => $beta->id]);
        $this->sale($this->seller($beta, 'Zulema'), $this->operator($beta, 'Zoe'), $branch, '2026-10-05', 'C8', [['net' => 8888.0, 'code' => 'ZZ']]);

        $this->assertStringNotContainsString('Zulema', json_encode($this->report(['company_id' => $beta->id])));
        $this->assertEquals(1050, $this->report()['ranking']['net_total']);
    }

    public function test_only_reports_viewers_can_read_it_and_the_query_is_audited(): void
    {
        $seller = User::factory()->role(Role::VENDEDOR)->create();
        $this->actingAs($seller, 'sanctum')->getJson('/api/v1/reports/products?'.http_build_query(self::PERIOD))->assertForbidden();

        $this->report();
        $this->assertTrue(AuditLog::query()->where('event', 'report.viewed')->where('new_values->report', 'products')->exists());
    }

    public function test_hostile_parameters_are_rejected(): void
    {
        foreach (['level' => 'product; drop table products', 'subject' => 'customer'] as $key => $value) {
            $this->actingAs($this->manager, 'sanctum')->getJson('/api/v1/reports/products?'.http_build_query(self::PERIOD + [$key => $value]))->assertUnprocessable();
        }
    }
}
