<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Product;
use App\Models\Role;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryLedger;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class InventoryUnitsApiTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private User $admin;

    private User $reader;

    private Product $oil;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->reader = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->reader->companies()->attach($this->a->id);
        $this->oil = Product::create(['company_id' => $this->a->id, 'code' => 'ACE-1', 'name' => 'Aceite 20W50']);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->reader->role_id, ['permissions' => ['inventory.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function id(string $code): int
    {
        return (int) Unit::where('code', $code)->value('id');
    }

    private function save(array $body, ?int $product = null)
    {
        return $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/inventory/products/'.($product ?? $this->oil->id).'/units', $body);
    }

    public function test_the_catalog_ships_with_the_agreed_units_and_can_be_extended(): void
    {
        $codes = array_column($this->actingAs($this->reader, 'sanctum')->getJson('/api/v1/inventory/units')->assertOk()->json('data'), 'code');
        $this->assertEqualsCanonicalizing(['galon', 'barril', 'cubeta', 'litro', 'octavo', '1_16', '1_32', '1_64', 'unidad'], $codes);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/inventory/units', ['code' => 'quintal', 'name' => 'Quintal'])->assertCreated();
        $this->postJson('/api/v1/inventory/units', ['code' => 'quintal', 'name' => 'Otro'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $this->postJson('/api/v1/inventory/units', ['code' => 'Con Espacio', 'name' => 'x'])->assertUnprocessable()->assertJsonValidationErrors(['code']);
        $id = Unit::where('code', 'quintal')->value('id');
        $this->putJson("/api/v1/inventory/units/$id", ['name' => 'Quintal (100 lb)', 'active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->putJson("/api/v1/inventory/units/$id", ['code' => 'otro'])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.unit_updated']);
    }

    public function test_only_inventory_manage_can_change_units(): void
    {
        $this->actingAs($this->reader, 'sanctum')->postJson('/api/v1/inventory/units', ['code' => 'x', 'name' => 'X'])->assertForbidden();
        $this->putJson('/api/v1/inventory/products/'.$this->oil->id.'/units', ['base_unit_id' => $this->id('galon'), 'equivalences' => []])->assertForbidden();
        $this->actingAs(User::factory()->role(Role::VENDEDOR)->create(), 'sanctum')->getJson('/api/v1/inventory/units')->assertForbidden();
    }

    public function test_it_sets_the_base_unit_and_exact_equivalences(): void
    {
        $this->save(['base_unit_id' => $this->id('galon'), 'equivalences' => [
            ['unit_id' => $this->id('barril'), 'factor' => '55'], ['unit_id' => $this->id('1_16'), 'factor' => '0.0625'], ['unit_id' => $this->id('litro'), 'factor' => '0.264172'],
        ]])->assertOk()->assertJsonPath('data.base_unit.code', 'galon');

        $show = $this->actingAs($this->reader, 'sanctum')->getJson('/api/v1/inventory/products/'.$this->oil->id.'/units')->assertOk()->json('data.equivalences');
        $this->assertEqualsCanonicalizing(['55', '0.0625', '0.264172'], array_column($show, 'factor'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'inventory.product_units_updated']);

        // Reemplaza el conjunto: lo que no viene se elimina.
        $this->save(['base_unit_id' => $this->id('galon'), 'equivalences' => [['unit_id' => $this->id('cubeta'), 'factor' => '5']]])->assertOk();
        $this->assertSame(['5'], array_column($this->getJson('/api/v1/inventory/products/'.$this->oil->id.'/units')->json('data.equivalences'), 'factor'));
    }

    public function test_equivalence_validations(): void
    {
        $galon = $this->id('galon');
        $this->save(['base_unit_id' => $galon, 'equivalences' => [['unit_id' => $galon, 'factor' => '1']]])->assertUnprocessable()->assertJsonValidationErrors(['equivalences.0.unit_id']);
        $this->save(['base_unit_id' => $galon, 'equivalences' => [['unit_id' => $this->id('cubeta'), 'factor' => '0']]])->assertUnprocessable()->assertJsonValidationErrors(['equivalences.0.factor']);
        $this->save(['base_unit_id' => $galon, 'equivalences' => [['unit_id' => $this->id('cubeta'), 'factor' => '1.1234567']]])->assertUnprocessable()->assertJsonValidationErrors(['equivalences.0.factor']);
        $this->save(['base_unit_id' => $galon, 'equivalences' => [['unit_id' => $this->id('cubeta'), 'factor' => '5'], ['unit_id' => $this->id('cubeta'), 'factor' => '6']]])->assertUnprocessable();
        $this->save(['base_unit_id' => 9999, 'equivalences' => []])->assertUnprocessable()->assertJsonValidationErrors(['base_unit_id']);
        $this->save(['base_unit_id' => $galon])->assertUnprocessable()->assertJsonValidationErrors(['equivalences']);
    }

    public function test_the_base_unit_cannot_change_once_the_product_has_movements(): void
    {
        $this->save(['base_unit_id' => $this->id('galon'), 'equivalences' => []])->assertOk();
        $branch = Branch::factory()->create(['company_id' => $this->a->id]);
        app(InventoryLedger::class)->record($this->a->id, $branch->id, $this->oil->refresh(), 'entry', '1', null, Carbon::parse('2026-10-01'), $this->admin, '10');

        $this->save(['base_unit_id' => $this->id('litro'), 'equivalences' => []])->assertUnprocessable()->assertJsonValidationErrors(['base_unit_id']);
        // Lo que sí se puede: ajustar equivalencias (los movimientos ya guardan su cantidad en unidad base).
        $this->save(['base_unit_id' => $this->id('galon'), 'equivalences' => [['unit_id' => $this->id('cubeta'), 'factor' => '5']]])->assertOk();
    }

    public function test_the_product_search_shows_missing_base_units(): void
    {
        $other = Product::create(['company_id' => $this->a->id, 'code' => 'FIL-9', 'name' => 'Filtro', 'base_unit_id' => $this->id('unidad')]);

        $all = $this->actingAs($this->reader, 'sanctum')->getJson('/api/v1/inventory/products')->assertOk()->json('data');
        $this->assertEqualsCanonicalizing(['ACE-1', 'FIL-9'], array_column($all, 'code'));
        $this->assertSame(['ACE-1'], array_column($this->getJson('/api/v1/inventory/products?missing_base=1')->json('data'), 'code'));
        $this->assertSame(['FIL-9'], array_column($this->getJson('/api/v1/inventory/products?q=fil')->json('data'), 'code'));
        $this->assertSame('unidad', $this->getJson('/api/v1/inventory/products?q=fil')->json('data.0.base_unit.code'));
        $this->assertNotNull($other->id);
    }

    public function test_the_product_list_is_paginated_by_the_server_with_totals(): void
    {
        foreach (range(1, 7) as $n) {
            Product::create(['company_id' => $this->a->id, 'code' => sprintf('P-%02d', $n), 'name' => sprintf('Producto %02d', $n)]);
        }
        $first = $this->actingAs($this->reader, 'sanctum')->getJson('/api/v1/inventory/products?per_page=3&missing_base=1')->assertOk();
        $this->assertSame(['ACE-1', 'P-01', 'P-02'], array_column($first->json('data'), 'code'));
        $this->assertSame([1, 3, 8, 3], [$first->json('meta.page'), $first->json('meta.per_page'), $first->json('meta.total'), $first->json('meta.last_page')]);

        $last = $this->getJson('/api/v1/inventory/products?per_page=3&page=3&missing_base=1')->assertOk();
        $this->assertSame(['P-06', 'P-07'], array_column($last->json('data'), 'code'));
        $this->assertSame(['P-03', 'P-04', 'P-05'], array_column($this->getJson('/api/v1/inventory/products?per_page=3&page=2&missing_base=1')->json('data'), 'code'));      // sin repetir ni saltar
        $this->assertCount(8, $this->getJson('/api/v1/inventory/products?missing_base=1')->json('data'));                  // sin paginar: 25 por página
        $this->getJson('/api/v1/inventory/products?per_page=500')->assertUnprocessable();
    }
}
