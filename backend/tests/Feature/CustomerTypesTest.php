<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Role;
use App\Models\SalesDocument;
use App\Models\SalesLine;
use App\Models\User;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTypesTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

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
        $branch = Branch::factory()->create(['company_id' => $this->a->id]);

        // Ventas recientes de A: C1 650 (92,9 % del total antes de C2 → A), C2 40 (B), C3 10 (C). C4 solo vendió hace más de un año.
        foreach ([['C1', 650, '2026-09-01'], ['C2', 40, '2026-10-01'], ['C3', 10, '2026-10-03'], ['C4', 5000, '2025-09-01']] as [$code, $net, $date]) {
            Customer::create(['company_id' => $this->a->id, 'branch_id' => $branch->id, 'code' => $code, 'name' => "Cliente $code", 'source' => 'import']);
            $doc = SalesDocument::factory()->create(['company_id' => $this->a->id, 'branch_id' => $branch->id, 'series' => 'S', 'number' => 'N'.++$this->seq, 'issued_on' => $date, 'customer_code' => $code, 'lines_count' => 1]);
            SalesLine::create([
                'sales_document_id' => $doc->id, 'company_id' => $this->a->id, 'branch_id' => $branch->id, 'issued_on' => $date, 'line_number' => 1,
                'product_code' => 'P', 'product_name' => 'P', 'category_name' => 'Cat', 'units' => 1, 'unit_cost' => 0, 'line_cost_total' => 0, 'unit_price' => 0,
                'net_total' => $net, 'tax_total' => 0, 'sale_plus_tax' => $net, 'grand_total' => $net,
            ]);
        }
        Customer::create(['company_id' => $this->b->id, 'code' => 'B1', 'name' => 'Cliente B1', 'source' => 'import']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_catalog_is_created_renamed_deactivated_and_audited(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/customers/types', ['name' => '  Taller  '])
            ->assertCreated()->assertJsonPath('data.name', 'Taller')->assertJsonPath('data.active', true)->json('data.id');
        $this->postJson('/api/v1/customers/types', ['name' => 'Taller'])->assertUnprocessable()->assertJsonValidationErrors(['name']);

        $this->putJson("/api/v1/customers/types/$id", ['name' => 'Taller mecánico'])->assertOk();
        $this->putJson("/api/v1/customers/types/$id", ['active' => false])->assertOk()->assertJsonPath('data.active', false);
        $this->putJson("/api/v1/customers/types/$id", ['name' => 'Taller mecánico'])->assertOk(); // sin cambios: no audita de nuevo

        $this->assertSame(['customer_types.created', 'customer_types.updated', 'customer_types.updated'], AuditLog::where('event', 'like', 'customer_types.%')->orderBy('id')->pluck('event')->all());
        $this->getJson('/api/v1/customers/types')->assertOk()->assertJsonPath('data.0.name', 'Taller mecánico');
    }

    public function test_assign_and_clear_type_filter_and_profile(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $taller = $this->postJson('/api/v1/customers/types', ['name' => 'Taller'])->json('data.id');
        $inactive = $this->postJson('/api/v1/customers/types', ['name' => 'Antiguo', 'active' => false])->json('data.id');

        $this->putJson('/api/v1/customers/type', ['company_id' => $this->a->id, 'code' => 'C1', 'customer_type_id' => $taller])->assertOk();
        $this->putJson('/api/v1/customers/type', ['company_id' => $this->a->id, 'code' => 'C2', 'customer_type_id' => $inactive])
            ->assertUnprocessable()->assertJsonValidationErrors(['customer_type_id']);
        $this->putJson('/api/v1/customers/type', ['company_id' => $this->a->id, 'code' => 'NOPE', 'customer_type_id' => $taller])->assertNotFound();
        $this->assertTrue(AuditLog::where('event', 'customers.type_updated')->exists());

        $rows = $this->getJson('/api/v1/customers?'.http_build_query(['type_id' => $taller]))->assertOk()->json('data');
        $this->assertSame(['C1'], array_column($rows, 'code'));
        $this->assertSame('Taller', $rows[0]['type']);

        $profile = $this->getJson('/api/v1/customers/profile?'.http_build_query(['code' => 'C1', 'company_id' => $this->a->id]))->json('data.customer');
        $this->assertSame('Taller', $profile['type']['name']);

        $this->putJson('/api/v1/customers/type', ['company_id' => $this->a->id, 'code' => 'C1', 'customer_type_id' => null])->assertOk();
        $this->assertNull(Customer::where('code', 'C1')->value('customer_type_id'));
    }

    public function test_abc_classes_use_trailing_twelve_months_of_net_sales(): void
    {
        $abc = collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/customers?q=Cliente')->json('data'))->pluck('abc', 'code');
        $this->assertSame('A', $abc['C1']);
        $this->assertSame('B', $abc['C2']);
        $this->assertSame('C', $abc['C3']);
        $this->assertNull($abc['C4']);   // su venta es de hace más de 12 meses
        $this->assertNull($abc['B1']);   // sin ventas

        $profile = $this->getJson('/api/v1/customers/profile?'.http_build_query(['code' => 'C2', 'company_id' => $this->a->id]))->json('data.customer');
        $this->assertSame('B', $profile['abc']);
    }

    public function test_managing_needs_its_own_permission(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['customers.view']])->assertOk();
        $user->unsetRelations();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/customers/types')->assertOk();
        $this->postJson('/api/v1/customers/types', ['name' => 'X'])->assertForbidden();
        $this->putJson('/api/v1/customers/type', ['company_id' => $this->a->id, 'code' => 'C1', 'customer_type_id' => null])->assertForbidden();
    }

    public function test_cannot_assign_a_type_to_a_customer_outside_the_users_scope(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['customers.view', 'customers.manage']])->assertOk();
        $user->unsetRelations();
        $type = $this->postJson('/api/v1/customers/types', ['name' => 'Taller'])->json('data.id');

        $this->actingAs($user, 'sanctum')->putJson('/api/v1/customers/type', ['company_id' => $this->b->id, 'code' => 'B1', 'customer_type_id' => $type])->assertNotFound();
    }
}
