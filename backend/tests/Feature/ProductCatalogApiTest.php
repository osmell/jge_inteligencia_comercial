<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use App\Models\User;
use App\Services\ProductCatalog;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductCatalogApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $a1;

    private Branch $a2;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->b = Company::factory()->create(['code' => 'B']);
        $this->a1 = Branch::factory()->create(['company_id' => $this->a->id]);
        $this->a2 = Branch::factory()->create(['company_id' => $this->a->id]);
        $b1 = Branch::factory()->create(['company_id' => $this->b->id]);

        Product::create(['company_id' => $this->a->id, 'code' => '100', 'name' => 'Filtro de aceite', 'level1' => 'Repuestos', 'level2' => 'Motor', 'level3' => 'Filtros']);
        Product::create(['company_id' => $this->a->id, 'code' => '200', 'name' => 'Pastilla de freno', 'level1' => 'Repuestos', 'level2' => 'Seguridad', 'level3' => 'Frenos']);
        Product::create(['company_id' => $this->a->id, 'code' => '300', 'name' => 'Aceite 20W50', 'level1' => 'Lubricantes', 'level2' => null, 'level3' => null]);
        Product::create(['company_id' => $this->b->id, 'code' => '100', 'name' => 'Producto de B', 'level1' => 'Otros']);

        $this->sale($this->a1, '100', 'Filtro de aceite', '2026-10-01', 400);
        $this->sale($this->a2, '100', 'Filtro de aceite', '2026-10-02', 100);
        $this->sale($this->a1, '999', 'Producto nuevo', '2026-10-03', 250);       // sin catálogo
        $this->sale($this->a1, '999', 'Producto nuevo', '2025-09-01', 5000);      // hace más de 12 meses: no cuenta
        $this->sale($b1, '999', 'Otro nuevo', '2026-10-04', 50);                  // mismo código en B, sin catálogo
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function sale(Branch $branch, string $code, string $name, string $date, int $net): void
    {
        $seller = Seller::firstOrCreate(['company_id' => $branch->company_id, 'normalized_name' => 'ana'], ['name' => 'Ana']);
        $doc = SalesDocument::factory()->create(['company_id' => $branch->company_id, 'branch_id' => $branch->id, 'series' => 'S', 'number' => 'N'.++$this->seq, 'issued_on' => $date, 'seller_id' => $seller->id, 'lines_count' => 1]);
        SalesLine::create([
            'sales_document_id' => $doc->id, 'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'issued_on' => $date, 'line_number' => 1,
            'product_code' => $code, 'product_name' => $name, 'category_name' => 'Cat '.$code, 'units' => 1, 'unit_cost' => 0, 'line_cost_total' => 0, 'unit_price' => 0,
            'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net,
        ]);
    }

    private function fetch(string $path, array $params = [], ?User $as = null)
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->getJson('/api/v1/sales/products'.$path.'?'.http_build_query($params));
    }

    public function test_search_filters_by_text_and_levels_and_shows_twelve_month_sales(): void
    {
        $rows = collect($this->fetch('', ['company_id' => $this->a->id])->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['100', '200', '300'], $rows->keys()->map(fn ($k) => (string) $k)->sort()->values()->all());
        $this->assertSame('500.00', $rows['100']['net_total']);
        $this->assertSame('0.00', $rows['200']['net_total']);
        $this->assertSame(['Repuestos', 'Motor', 'Filtros'], [$rows['100']['level1'], $rows['100']['level2'], $rows['100']['level3']]);

        $this->assertSame(['200'], array_column($this->fetch('', ['q' => 'PASTILLA'])->json('data'), 'code'));
        $this->assertSame(['100', '200'], collect($this->fetch('', ['company_id' => $this->a->id, 'level1' => 'Repuestos', 'sort' => 'code'])->json('data'))->pluck('code')->all());
        $this->assertSame(['100'], array_column($this->fetch('', ['company_id' => $this->a->id, 'level2' => 'Motor'])->json('data'), 'code'));
        $this->assertSame(['300'], array_column($this->fetch('', ['company_id' => $this->a->id, 'level3' => ProductCatalog::NONE])->json('data'), 'code'));
        $this->assertSame('100', $this->fetch('', ['company_id' => $this->a->id, 'sort' => 'net_total'])->json('data.0.code'));
        $this->assertSame([], $this->fetch('', ['q' => '%'])->json('data'));
    }

    public function test_levels_are_narrowed_by_the_parent_level(): void
    {
        $all = $this->fetch('/levels', ['company_id' => $this->a->id])->assertOk()->json('data');
        $this->assertSame(['Lubricantes', 'Repuestos'], $all['level1']);
        $this->assertSame(['Motor', 'Seguridad'], $all['level2']);

        $narrow = $this->fetch('/levels', ['company_id' => $this->a->id, 'level1' => 'Repuestos', 'level2' => 'Motor'])->json('data');
        $this->assertSame(['Motor', 'Seguridad'], $narrow['level2']);
        $this->assertSame(['Filtros'], $narrow['level3']);
        $this->assertSame([], $this->fetch('/levels', ['company_id' => $this->a->id, 'level1' => 'Lubricantes'])->json('data.level2'));
    }

    public function test_missing_products_and_coverage_use_the_last_twelve_months(): void
    {
        $missing = $this->fetch('/missing')->assertOk()->json('data');
        $this->assertSame([['999', $this->a->id, '250.00'], ['999', $this->b->id, '50.00']], array_map(fn ($r) => [$r['code'], $r['company_id'], $r['net_total']], $missing));
        $this->assertSame('Producto nuevo', $missing[0]['name']);

        $c = $this->fetch('/coverage')->assertOk()->json('data');
        $this->assertSame(4, $c['catalog_products']);
        $this->assertSame(3, $c['sold_products']);        // A-100, A-999, B-999
        $this->assertSame(2, $c['missing_products']);
        $this->assertSame('800.00', $c['net_total']);
        $this->assertSame('300.00', $c['missing_net_total']);
        $this->assertSame(62.5, $c['covered_pct']);
    }

    public function test_scope_and_permission(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $user->branches()->attach($this->a2);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['sales.view']])->assertOk();
        $user->unsetRelations();

        // El catálogo es de la compañía (sin B); las ventas, solo de la sucursal Norte.
        $codes = collect($this->fetch('', [], $user)->assertOk()->json('data'))->keyBy('code');
        $this->assertSame(['100', '200', '300'], $codes->keys()->map(fn ($k) => (string) $k)->sort()->values()->all());
        $this->assertSame('100.00', $codes['100']['net_total']);
        $this->assertSame(0, $this->fetch('/coverage', [], $user)->json('data.missing_products'));

        $sin = User::factory()->role(Role::VENDEDOR)->create();
        $sin->companies()->attach($this->a);
        $this->fetch('', [], $sin)->assertForbidden();
    }
}
