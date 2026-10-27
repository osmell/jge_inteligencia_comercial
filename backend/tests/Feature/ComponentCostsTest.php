<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Role;
use App\Models\SalesLine;
use App\Models\User;
use App\Services\Sales\ComponentCosts;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\BuildsSalesFixtures;
use Tests\TestCase;

class ComponentCostsTest extends TestCase
{
    use BuildsSalesFixtures, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        // La pintura (principal), una línea menor, un catalizador y un reductor de precio simbólico.
        $this->sale($this->seller($company, 'A', $branch), $this->operator($company, 'O'), $branch, '2026-08-03', 'C1', [
            ['qty' => 1, 'price' => 600, 'net' => 600, 'code' => 'PINTURA', 'name' => 'PINTURA'],
            ['qty' => 1, 'price' => 80, 'net' => 80, 'code' => 'BROCHA', 'name' => 'BROCHA'],
            ['qty' => 0.5, 'price' => 0.0087, 'net' => 0.0044, 'code' => 'CATALIZADOR', 'name' => 'CATALIZADOR'],
            ['qty' => 1, 'price' => 0.01, 'net' => 0.01, 'code' => 'REDUCTOR', 'name' => 'REDUCTOR'],
        ]);
        foreach ([1 => 400, 2 => 50, 3 => 120, 4 => 30] as $line => $cost) {
            DB::table('sales_lines')->where('line_number', $line)->update(['line_cost_total' => $cost]);
        }
    }

    /** @return array<string, array<string, mixed>> margen por producto */
    private function byProduct(string $extra = ''): array
    {
        $admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $rows = $this->actingAs($admin, 'sanctum')->getJson('/api/v1/sales/breakdown?dimension=product&from=2026-08-01&to=2026-08-31'.$extra)->assertOk()->json('data');

        return collect($rows)->keyBy('label')->all();
    }

    public function test_the_cost_of_components_goes_to_the_main_line_without_touching_the_lines(): void
    {
        $this->assertSame(2, app(ComponentCosts::class)->run());

        $main = SalesLine::where('product_code', 'PINTURA')->sole();
        $this->assertEquals(150, DB::table('sales_line_component_costs')->where('main_line_id', $main->id)->sum('cost'));
        $this->assertEquals(400, $main->line_cost_total, 'La línea original conserva su costo.');
        $this->assertEquals(120, SalesLine::where('product_code', 'CATALIZADOR')->sole()->line_cost_total);
        $this->assertSame(0, app(ComponentCosts::class)->run(), 'Es idempotente.');
    }

    public function test_the_margin_uses_the_reassigned_cost_and_the_imported_basis_the_file_cost(): void
    {
        app(ComponentCosts::class)->run();

        $corrected = $this->byProduct();
        $this->assertEquals(50, $corrected['PINTURA']['estimated_margin']);          // 600 − (400 + 120 + 30)
        $this->assertNull($corrected['CATALIZADOR']['estimated_margin']);             // sin costo propio: no se evalúa

        $imported = $this->byProduct('&basis=imported');
        $this->assertEquals(200, $imported['PINTURA']['estimated_margin']);           // 600 − 400
        $this->assertEquals(-120, $imported['CATALIZADOR']['estimated_margin']);   // el componente aparecía con pérdida
    }
}
