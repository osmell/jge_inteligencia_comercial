<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerAssignmentTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Company $b;

    private Branch $centro;

    private Branch $norte;

    private User $admin;

    private User $sup;

    private Seller $ana;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->sup = User::factory()->role(Role::SUPERVISOR_VENTAS)->create(['name' => 'Sara Supervisora']);
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->b = Company::factory()->create(['code' => 'B']);
        $this->centro = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Centro']);
        $this->norte = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Norte']);
        $this->ana = Seller::create(['company_id' => $this->a->id, 'name' => 'Ana', 'normalized_name' => 'ana']);
        Seller::ensureStoreSeller($this->centro);
        Seller::ensureStoreSeller($this->norte);
        Customer::create(['company_id' => $this->a->id, 'branch_id' => $this->centro->id, 'code' => 'C1', 'name' => 'Cliente 1', 'source' => 'import']);
    }

    private function profile(string $code = 'C1', ?User $as = null): array
    {
        return $this->actingAs($as ?? $this->admin, 'sanctum')->getJson('/api/v1/customers/profile?'.http_build_query(['code' => $code, 'company_id' => $this->a->id]))->assertOk()->json('data.assignment');
    }

    public function test_creating_a_branch_creates_its_default_store_seller_and_reuses_an_existing_one(): void
    {
        $id = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/branches', ['company_id' => $this->a->id, 'code' => 'S3', 'name' => 'Sur'])->assertCreated()->json('data.id');
        $store = Seller::where('branch_id', $id)->first();
        $this->assertSame('Tienda Sur', $store->name);
        $this->assertTrue($store->is_default);

        $this->assertSame($store->id, Seller::ensureStoreSeller(Branch::find($id))->id);
        $this->assertSame(1, Seller::where('company_id', $this->a->id)->where('normalized_name', 'tienda sur')->count());
    }

    public function test_customer_without_seller_uses_its_branch_store_and_supervisor_comes_from_the_seller(): void
    {
        $a = $this->profile();
        $this->assertSame(['name' => 'Tienda Centro', 'assigned' => false], ['name' => $a['seller']['name'], 'assigned' => $a['seller']['assigned']]);
        $this->assertNull($a['supervisor']);

        $op = Operator::create(['company_id' => $this->a->id, 'name' => 'Caja 1', 'normalized_name' => 'caja 1']);
        $this->putJson('/api/v1/customers/sellers/'.$this->ana->id, ['supervisor_id' => $this->sup->id])->assertOk()->assertJsonPath('data.supervisor.name', 'Sara Supervisora');
        $this->putJson('/api/v1/customers/assignment', ['company_id' => $this->a->id, 'code' => 'C1', 'seller_id' => $this->ana->id, 'operator_id' => $op->id])->assertOk();

        $a = $this->profile();
        $this->assertSame(['Ana', true, 'Caja 1', 'Sara Supervisora'], [$a['seller']['name'], $a['seller']['assigned'], $a['operator']['name'], $a['supervisor']['name']]);

        $row = collect($this->getJson('/api/v1/customers?q=C1')->json('data'))->first();
        $this->assertSame('Ana', $row['seller']);
        $this->assertFalse($row['seller_is_default']);
        $this->assertTrue(AuditLog::where('event', 'customers.assignment_updated')->exists());
        $this->assertTrue(AuditLog::where('event', 'sellers.updated')->exists());

        $this->putJson('/api/v1/customers/assignment', ['company_id' => $this->a->id, 'code' => 'C1', 'seller_id' => null, 'operator_id' => null])->assertOk();
        $this->assertSame('Tienda Centro', $this->profile()['seller']['name']);
    }

    public function test_assignment_rejects_people_from_another_company(): void
    {
        $other = Seller::create(['company_id' => $this->b->id, 'name' => 'Otro', 'normalized_name' => 'otro']);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/customers/assignment', ['company_id' => $this->a->id, 'code' => 'C1', 'seller_id' => $other->id, 'operator_id' => null])
            ->assertUnprocessable()->assertJsonValidationErrors(['seller_id']);
        $this->putJson('/api/v1/customers/assignment', ['company_id' => $this->a->id, 'code' => 'NOPE', 'seller_id' => null, 'operator_id' => null])->assertNotFound();
    }

    public function test_only_one_default_store_per_branch_and_it_needs_a_branch(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $this->putJson('/api/v1/customers/sellers/'.$this->ana->id, ['is_default' => true])->assertUnprocessable()->assertJsonValidationErrors(['is_default']);

        $this->putJson('/api/v1/customers/sellers/'.$this->ana->id, ['is_default' => true, 'branch_id' => $this->centro->id])->assertOk();
        $this->assertSame([$this->ana->id], Seller::where('branch_id', $this->centro->id)->where('is_default', true)->pluck('id')->all());

        $this->putJson('/api/v1/customers/sellers/'.$this->ana->id, ['branch_id' => Branch::factory()->create(['company_id' => $this->b->id])->id])->assertUnprocessable()->assertJsonValidationErrors(['branch_id']);
        $this->putJson('/api/v1/customers/sellers/'.$this->ana->id, ['supervisor_id' => $this->admin->id])->assertUnprocessable()->assertJsonValidationErrors(['supervisor_id']);
    }

    public function test_lists_and_permissions(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['customers.view']])->assertOk();
        $user->unsetRelations();

        $names = collect($this->actingAs($user, 'sanctum')->getJson('/api/v1/customers/sellers')->assertOk()->json('data'))->pluck('name')->all();
        $this->assertEqualsCanonicalizing(['Ana', 'Tienda Centro', 'Tienda Norte'], $names);
        $this->getJson('/api/v1/customers/supervisors')->assertOk()->assertJsonPath('data.0.name', 'Sara Supervisora');
        $this->getJson('/api/v1/customers/operators')->assertOk();
        $this->putJson('/api/v1/customers/sellers/'.$this->ana->id, ['supervisor_id' => $this->sup->id])->assertForbidden();
        $this->putJson('/api/v1/customers/assignment', ['company_id' => $this->a->id, 'code' => 'C1', 'seller_id' => null, 'operator_id' => null])->assertForbidden();
    }

    public function test_a_seller_user_only_sees_the_customers_assigned_to_them(): void
    {
        Customer::create(['company_id' => $this->a->id, 'branch_id' => $this->centro->id, 'code' => 'C2', 'name' => 'Cliente 2', 'source' => 'import', 'seller_id' => $this->ana->id]);
        $vendedor = User::factory()->role(Role::VENDEDOR)->create();
        $vendedor->companies()->attach($this->a);
        $this->ana->update(['user_id' => $vendedor->id]);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$vendedor->role_id, ['permissions' => ['customers.view']])->assertOk();
        $vendedor->unsetRelations();

        $codes = collect($this->actingAs($vendedor, 'sanctum')->getJson('/api/v1/customers')->assertOk()->json('data'))->pluck('code')->all();
        $this->assertSame(['C2'], $codes);
        $this->getJson('/api/v1/customers/profile?'.http_build_query(['code' => 'C1', 'company_id' => $this->a->id]))->assertNotFound();
        $this->getJson('/api/v1/customers/profile?'.http_build_query(['code' => 'C2', 'company_id' => $this->a->id]))->assertOk();
    }
}
