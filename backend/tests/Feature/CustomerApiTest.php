<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\Seller;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $a1;

    private Branch $a2;

    private Seller $ana;

    private User $admin;

    private int $docSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->b = Company::factory()->create(['code' => 'B']);
        $this->a1 = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Centro']);
        $this->a2 = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Norte']);
        $b1 = Branch::factory()->create(['company_id' => $this->b->id, 'name' => 'Sur']);
        $this->ana = Seller::create(['company_id' => $this->a->id, 'name' => 'Ana', 'normalized_name' => 'ana']);
        $luis = Seller::create(['company_id' => $this->a->id, 'name' => 'Luis', 'normalized_name' => 'luis']);
        $otro = Seller::create(['company_id' => $this->b->id, 'name' => 'Otro', 'normalized_name' => 'otro']);

        // Cliente C1: 3 facturas en 3 fechas (0, 10 y 30 días tras la primera). Una de ellas con otro nombre y otro vendedor.
        $this->sale($this->a1, $this->ana, 'C1', 'Taller Pérez', '2026-08-01', [['P1', 'Filtro', 'Filtros', 100], ['P2', 'Aceite', 'Aceites', 50]]);
        $this->sale($this->a1, $this->ana, 'C1', 'Taller Pérez', '2026-08-11', [['P1', 'Filtro', 'Filtros', 200]]);
        $this->sale($this->a2, $luis, 'C1', 'TALLER PEREZ S.A.', '2026-08-31', [['P3', 'Freno', 'Frenos', 300]]);
        // Otros clientes.
        $this->sale($this->a1, $this->ana, 'C2', 'Ferretería Gómez', '2026-10-01', [['P1', 'Filtro', 'Filtros', 40]]);
        $this->sale($b1, $otro, 'C9', 'Cliente de B', '2026-10-02', [['P1', 'Filtro', 'Filtros', 900]]);
        $this->sale($this->a1, $this->ana, '=EVIL', '=Cliente raro', '2026-10-03', [['P1', 'Filtro', 'Filtros', 10]]);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param list<array{0: string, 1: string, 2: string, 3: int}> $lines producto, nombre, categoría, neto */
    private function sale(Branch $branch, Seller $seller, string $code, string $name, string $date, array $lines): void
    {
        $doc = SalesDocument::factory()->create([
            'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'series' => 'S', 'number' => 'N'.++$this->docSeq,
            'issued_on' => $date, 'customer_code' => $code, 'customer_name' => $name, 'seller_id' => $seller->id, 'lines_count' => count($lines),
        ]);
        foreach ($lines as $i => [$product, $productName, $category, $net]) {
            SalesLine::create([
                'sales_document_id' => $doc->id, 'company_id' => $branch->company_id, 'branch_id' => $branch->id, 'issued_on' => $date,
                'line_number' => $i + 1, 'product_code' => $product, 'product_name' => $productName, 'category_name' => $category, 'units' => 2,
                'unit_cost' => 0, 'line_cost_total' => $net / 2, 'unit_price' => 0, 'net_total' => $net, 'tax_total' => $net * 0.12,
                'sale_plus_tax' => $net * 1.12, 'grand_total' => $net * 1.12,
            ]);
        }
    }

    private function fetch(string $path, array $params = [], ?User $as = null): TestResponse
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->getJson('/api/v1/customers'.$path.'?'.http_build_query($params));
    }

    public function test_search_by_code_or_name_without_accents_matters_and_sorts(): void
    {
        $byName = $this->fetch('', ['q' => 'taller'])->assertOk()->json('data');
        $this->assertSame(['C1'], array_column($byName, 'code'));
        $this->assertSame(3, $byName[0]['documents']);
        $this->assertSame('650.00', $byName[0]['net_total']);
        $this->assertSame('2026-08-01', $byName[0]['first_purchase']);
        $this->assertSame('2026-08-31', $byName[0]['last_purchase']);

        $this->assertSame(['C2'], array_column($this->fetch('', ['q' => 'c2'])->json('data'), 'code'));

        // Sin término: todos, del más reciente al más antiguo; y por venta neta.
        $this->assertSame(['=EVIL', 'C9', 'C2', 'C1'], array_column($this->fetch('')->json('data'), 'code'));
        $this->assertSame(['C9', 'C1', 'C2', '=EVIL'], array_column($this->fetch('', ['sort' => 'net_total'])->json('data'), 'code'));
        $this->assertSame(['C1'], array_column($this->fetch('', ['company_id' => $this->a->id, 'q' => 'taller'])->json('data'), 'code'));
        $this->assertSame([], $this->fetch('', ['company_id' => $this->b->id, 'q' => 'taller'])->json('data'));
    }

    public function test_search_treats_percent_and_underscore_literally(): void
    {
        $this->assertSame([], $this->fetch('', ['q' => '%'])->json('data'));
        $this->assertSame([], $this->fetch('', ['q' => 'c_'])->json('data'));
    }

    public function test_search_paginates(): void
    {
        foreach (range(1, 25) as $i) {
            $this->sale($this->a1, $this->ana, sprintf('X%02d', $i), "Cliente $i", '2026-09-01', [['P1', 'Filtro', 'Filtros', 1]]);
        }

        $first = $this->fetch('', ['q' => 'cliente', 'sort' => 'name'])->assertOk();
        $this->assertCount(20, $first->json('data'));
        $this->assertTrue($first->json('meta.has_more'));

        $second = $this->fetch('', ['q' => 'cliente', 'sort' => 'name', 'page' => 2])->json();
        $this->assertCount(7, $second['data']);                            // 25 + «Cliente raro» + «Cliente de B» = 27
        $this->assertFalse($second['meta']['has_more']);
    }

    public function test_profile_totals_frequency_and_name_variants(): void
    {
        $p = $this->fetch('/profile', ['code' => 'C1'])->assertOk()->json('data');

        $this->assertSame('650.00', $p['totals']['net_total']);
        $this->assertSame(3, $p['totals']['documents']);
        $this->assertSame('216.67', $p['totals']['average_ticket']);
        $this->assertSame('2026-08-01', $p['first_purchase']);
        $this->assertSame('2026-08-31', $p['last_purchase']);
        $this->assertSame(50, $p['days_since_last_purchase']);               // al 2026-10-20
        $this->assertEquals(['purchase_days' => 3, 'average_days_between' => 15], $p['frequency']);
        $this->assertSame('TALLER PEREZ S.A.', $p['name']);                   // el más reciente
        $this->assertSame(['TALLER PEREZ S.A.', 'Taller Pérez'], array_column($p['name_variants'], 'name'));
        $this->assertSame('2026-08-01', $p['history_starts_on']);

        $this->assertSame(['P1', 'P3', 'P2'], array_column($p['top_products'], 'key'));
        $this->assertSame('300.00', $p['top_products'][0]['net_total']);
        $this->assertSame(['Ana', 'Luis'], array_column($p['sellers'], 'label'));
        $this->assertSame(['Centro', 'Norte'], array_column($p['branches'], 'label'));
        $this->assertSame(['Filtros', 'Frenos', 'Aceites'], array_column($p['top_categories'], 'key'));
        $this->assertSame('estimated_with_current_cost', $p['notes']['margin']);
    }

    public function test_profile_monthly_series_has_twelve_months_with_zeros(): void
    {
        $monthly = $this->fetch('/profile', ['code' => 'C1'])->json('data.monthly');

        $this->assertCount(12, $monthly);
        $this->assertSame('2025-11', $monthly[0]['key']);
        $this->assertSame('2026-10', $monthly[11]['key']);
        $this->assertSame('0.00', $monthly[0]['net_total']);
        $this->assertSame('650.00', collect($monthly)->firstWhere('key', '2026-08')['net_total']);
    }

    public function test_single_purchase_has_no_average_interval(): void
    {
        $p = $this->fetch('/profile', ['code' => 'C2'])->json('data');

        $this->assertNull($p['frequency']['average_days_between']);
        $this->assertSame(1, $p['frequency']['purchase_days']);
    }

    public function test_unknown_customer_is_404_and_code_is_required(): void
    {
        $this->fetch('/profile', ['code' => 'NOPE'])->assertNotFound();
        $this->fetch('/profile')->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->fetch('/purchases')->assertUnprocessable();
        $this->fetch('/document', ['code' => 'C1'])->assertUnprocessable()->assertJsonValidationErrors(['document_id']);
    }

    public function test_codes_with_special_characters_work(): void
    {
        $this->fetch('/profile', ['code' => '=EVIL'])->assertOk()->assertJsonPath('data.code', '=EVIL');
    }

    public function test_purchases_list_and_document_lines(): void
    {
        $purchases = $this->fetch('/purchases', ['code' => 'C1'])->assertOk()->json('data');

        $this->assertSame(['2026-08-31', '2026-08-11', '2026-08-01'], array_column($purchases, 'issued_on'));
        $this->assertSame('Norte', $purchases[0]['branch']);
        $this->assertSame('Luis', $purchases[0]['seller']);
        $this->assertSame(['300.00', '200.00', '150.00'], array_column($purchases, 'net_total'));
        $this->assertSame(2, $purchases[2]['lines']);

        $lines = $this->fetch('/document', ['code' => 'C1', 'document_id' => $purchases[2]['id']])->assertOk()->json('data');
        $this->assertSame(['P1', 'P2'], array_column($lines, 'product_code'));
        $this->assertSame('100.00', $lines[0]['net_total']);

        // Una factura de otro cliente no se puede leer pasando un código ajeno.
        $other = SalesDocument::where('customer_code', 'C2')->value('id');
        $this->fetch('/document', ['code' => 'C1', 'document_id' => $other])->assertNotFound();
    }

    public function test_user_only_sees_their_scope_and_needs_the_permission(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $user->branches()->attach($this->a2);
        $this->actingAs($this->admin, 'sanctum')
            ->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['customers.view']])->assertOk();
        $user->unsetRelations();

        // Solo la sucursal Norte: del cliente C1 ve únicamente la factura de Luis.
        $p = $this->fetch('/profile', ['code' => 'C1'], $user)->assertOk()->json('data');
        $this->assertSame('300.00', $p['totals']['net_total']);
        $this->assertSame(['C1'], array_column($this->fetch('', [], $user)->json('data'), 'code'));
        $this->fetch('/profile', ['code' => 'C9'], $user)->assertNotFound();

        $sin = User::factory()->role(Role::VENDEDOR)->create();
        $sin->companies()->attach($this->a);
        $this->fetch('', [], $sin)->assertForbidden();
        $this->fetch('/profile', ['code' => 'C1'], $sin)->assertForbidden();
    }
}
