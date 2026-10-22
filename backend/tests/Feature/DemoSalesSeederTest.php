<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\Demo\DemoSalesGenerator;
use Database\Seeders\DemoSalesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class DemoSalesSeederTest extends TestCase
{
    use RefreshDatabase;

    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::parse('2026-10-19');
        CarbonImmutable::setTestNow($this->today);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return list<array<string, mixed>> */
    private function all(float $scale = 1.0): array
    {
        // Un destructor pendiente de pruebas anteriores podría consumir números aleatorios en mitad de la generación.
        gc_collect_cycles();

        return iterator_to_array((new DemoSalesGenerator($scale))->documents($this->today), false);
    }

    private function seedDemo(float $scale = 0.05): void
    {
        app(DemoSalesSeeder::class)->scale($scale)->run();
    }

    public function test_generation_is_reproducible(): void
    {
        $this->assertSame(md5(json_encode($this->all(0.1))), md5(json_encode($this->all(0.1))));
    }

    public function test_full_scale_is_about_six_thousand_documents_and_seventeen_thousand_lines(): void
    {
        $docs = $this->all();
        $lines = array_sum(array_map(fn ($d) => count($d['lines']), $docs));

        $this->assertGreaterThan(5000, count($docs));
        $this->assertLessThan(7500, count($docs));
        $this->assertGreaterThan(16000, $lines);
        $this->assertLessThan(26000, $lines);
        $this->assertSame('2025-11-01', $docs[0]['issued_on']);                    // 12 meses completos hasta hoy
        $this->assertSame('2026-10-19', end($docs)['issued_on']);
    }

    public function test_every_line_is_internally_consistent(): void
    {
        foreach ($this->all(0.3) as $doc) {
            $this->assertNotEmpty($doc['lines']);
            foreach ($doc['lines'] as $l) {
                $net = round($l['units'] * $l['unit_price'], 4);
                $this->assertEqualsWithDelta($net, (float) $l['net_total'], 0.00005);
                $this->assertEqualsWithDelta($net * DemoSalesGenerator::TAX_RATE, (float) $l['tax_total'], 0.0001);
                $this->assertEqualsWithDelta((float) $l['net_total'] + (float) $l['tax_total'], (float) $l['sale_plus_tax'], 0.0001);
                $this->assertSame($l['sale_plus_tax'], $l['grand_total']);              // Venta + Imp = Gran Total
                $this->assertEqualsWithDelta($l['units'] * $l['unit_cost'], (float) $l['line_cost_total'], 0.0001);
                $this->assertLessThanOrEqual((float) $l['normal_price'], (float) $l['unit_price']);
                $this->assertGreaterThan(0, (float) $l['unit_cost']);
                $this->assertLessThan((float) $l['normal_price'], (float) $l['unit_cost']);
                $this->assertMatchesRegularExpression('/^\d{6}$/', $l['product_code']);   // códigos de texto con ceros iniciales
            }
        }
    }

    public function test_data_has_the_patterns_the_dashboards_need(): void
    {
        $docs = collect($this->all());

        $perMonth = $docs->groupBy(fn ($d) => substr($d['issued_on'], 0, 7))->map->count();
        $this->assertGreaterThan($perMonth['2026-02'] * 1.3, $perMonth['2025-12']);   // diciembre > febrero (estacionalidad)
        $this->assertGreaterThan($perMonth['2025-11'], $perMonth['2026-09']);         // crecimiento durante el año

        $sundays = $docs->filter(fn ($d) => CarbonImmutable::parse($d['issued_on'])->isSunday())->count();
        $this->assertLessThan($docs->count() * 0.06, $sundays);                      // poca venta en domingo

        $half = fn ($seller, $from, $to) => $docs->where('seller', $seller)->filter(fn ($d) => $d['issued_on'] >= $from && $d['issued_on'] <= $to)->count();
        $this->assertGreaterThan($half('Marcos Solís', '2026-05-01', '2026-10-19') * 1.5, $half('Marcos Solís', '2025-11-01', '2026-04-30')); // en descenso

        $alfaCustomers = $docs->where('company', 'ALFA')->countBy('customer_code')->sortDesc();
        $topTenth = $alfaCustomers->take((int) ceil($alfaCustomers->count() / 10))->sum();
        $this->assertGreaterThan($alfaCustomers->sum() * 0.3, $topTenth);            // clientes concentrados

        $lines = $docs->flatMap(fn ($d) => $d['lines']);
        $discounted = $lines->filter(fn ($l) => (float) $l['unit_price'] < (float) $l['normal_price'])->count();
        $this->assertEqualsWithDelta(0.30, $discounted / $lines->count(), 0.05);     // ~30 % con descuento
        $this->assertGreaterThan(0.5, $docs->filter(fn ($d) => count($d['lines']) > 1)->count() / $docs->count()); // facturas de varias líneas

        $this->assertSame(['ALFA', 'BETA'], $docs->pluck('company')->unique()->sort()->values()->all());
    }

    public function test_seeder_writes_consistent_documents_lines_and_a_demo_batch(): void
    {
        $expected = $this->all(0.05);
        $this->seedDemo(0.05);

        $this->assertSame(count($expected), SalesDocument::withoutGlobalScopes()->count());
        $this->assertSame(array_sum(array_map(fn ($d) => count($d['lines']), $expected)), SalesLine::withoutGlobalScopes()->count());
        $this->assertSame(['DEMO'], SalesDocument::withoutGlobalScopes()->pluck('series')->unique()->values()->all());

        // Los totales de la base coinciden con una suma independiente en PHP sobre lo generado.
        $netExpected = array_sum(array_map(fn ($d) => array_sum(array_column($d['lines'], 'net_total')), $expected));
        $this->assertEqualsWithDelta($netExpected, (float) SalesLine::withoutGlobalScopes()->sum('net_total'), 0.01);

        // Integridad: la sucursal pertenece a la compañía, y vendedor y operador también.
        $this->assertSame(0, DB::table('sales_documents as d')->join('branches as b', 'b.id', '=', 'd.branch_id')->whereColumn('b.company_id', '!=', 'd.company_id')->count());
        $this->assertSame(0, DB::table('sales_documents as d')->join('sellers as s', 's.id', '=', 'd.seller_id')->whereColumn('s.company_id', '!=', 'd.company_id')->count());
        $this->assertSame(0, DB::table('sales_documents as d')->join('operators as o', 'o.id', '=', 'd.operator_id')->whereColumn('o.company_id', '!=', 'd.company_id')->count());
        $this->assertSame(0, DB::table('sales_lines as l')->join('sales_documents as d', 'd.id', '=', 'l.sales_document_id')->whereColumn('l.company_id', '!=', 'd.company_id')->count());
        $this->assertSame(0, DB::table('sales_documents as d')->whereColumn('lines_count', '!=', DB::raw('(select count(*) from sales_lines l where l.sales_document_id = d.id)'))->count());

        $batch = ImportBatch::sole();
        $this->assertStringContainsString('DEMOSTRACIÓN', $batch->original_name);
        $this->assertSame(count($expected), $batch->documents_total);
    }

    public function test_seeder_is_repeatable_without_duplicating(): void
    {
        $this->seedDemo();
        $first = [SalesDocument::withoutGlobalScopes()->count(), SalesLine::withoutGlobalScopes()->count(), Seller::count()];

        $this->seedDemo();

        $this->assertSame($first, [SalesDocument::withoutGlobalScopes()->count(), SalesLine::withoutGlobalScopes()->count(), Seller::count()]);
        $this->assertSame(1, ImportBatch::count());
    }

    public function test_real_sales_are_never_touched_by_the_demo_seeder(): void
    {
        $this->seedDemo();
        $real = SalesDocument::factory()->create(['series' => 'A', 'number' => '000001']);

        $this->seedDemo();

        $this->assertTrue(SalesDocument::withoutGlobalScopes()->whereKey($real->id)->exists());
        $this->assertSame(1, SalesDocument::withoutGlobalScopes()->where('series', 'A')->count());
    }

    public function test_demo_sellers_are_linked_to_the_demo_users(): void
    {
        $this->seedDemo();

        $this->assertSame(User::where('username', 'vendedor1')->value('id'), Seller::where('name', 'Vendedor Uno Demo')->value('user_id'));
        $this->assertSame(User::where('username', 'vendedor2')->value('id'), Seller::where('name', 'Vendedor Dos Demo')->value('user_id'));
        $this->assertSame(2, Seller::whereNotNull('user_id')->count());
        $this->assertSame(8, Seller::count());
        $this->assertSame(Company::where('code', 'ALFA')->value('id'), Seller::where('name', 'Ana Torres')->value('company_id'));
        $this->assertSame(3, Branch::count());
    }

    public function test_it_refuses_to_run_in_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $this->seedDemo();
    }
}
