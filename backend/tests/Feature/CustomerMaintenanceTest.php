<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\Operator;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\DataScope;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerMaintenanceTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $centro;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->centro = Branch::factory()->create(['company_id' => $this->a->id, 'name' => 'Centro']);
        $this->actingAs($this->admin, 'sanctum');
    }

    public function test_seller_is_created_renamed_deactivated_and_linked_to_a_user(): void
    {
        $vendedor = User::factory()->role(Role::VENDEDOR)->create(['name' => 'Vera']);
        $id = $this->postJson('/api/v1/customers/sellers', ['company_id' => $this->a->id, 'name' => '  Ana  Torres ', 'branch_id' => $this->centro->id, 'user_id' => $vendedor->id])
            ->assertCreated()->assertJsonPath('data.user_id', $vendedor->id)->json('data.id');
        $this->assertSame('ana torres', Seller::find($id)->normalized_name);

        $this->postJson('/api/v1/customers/sellers', ['company_id' => $this->a->id, 'name' => 'ANA TORRES'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->putJson("/api/v1/customers/sellers/$id", ['name' => 'Ana T.', 'is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame('ana t.', Seller::find($id)->normalized_name);
        $this->putJson("/api/v1/customers/sellers/$id", ['user_id' => $this->admin->id])->assertUnprocessable()->assertJsonValidationErrors(['user_id']);
        $this->putJson("/api/v1/customers/sellers/$id", ['user_id' => null])->assertOk();
        $this->assertNull(Seller::find($id)->user_id);

        $this->assertSame(1, AuditLog::where('event', 'sellers.created')->count());
        $this->assertSame(2, AuditLog::where('event', 'sellers.updated')->count());
        $this->getJson('/api/v1/customers/seller-users')->assertOk()->assertJsonPath('data.0.name', 'Vera');
    }

    public function test_operator_maintenance(): void
    {
        $id = $this->postJson('/api/v1/customers/operators', ['company_id' => $this->a->id, 'name' => 'Caja 1'])->assertCreated()->json('data.id');
        $this->postJson('/api/v1/customers/operators', ['company_id' => $this->a->id, 'name' => 'caja  1'])->assertUnprocessable()->assertJsonValidationErrors(['name']);
        $this->putJson("/api/v1/customers/operators/$id", ['name' => 'Caja Principal'])->assertOk();
        $this->putJson("/api/v1/customers/operators/$id", ['is_active' => false])->assertOk()->assertJsonPath('data.is_active', false);
        $this->assertSame('caja principal', Operator::find($id)->normalized_name);
        $this->assertSame(['operators.created', 'operators.updated', 'operators.updated'], AuditLog::where('event', 'like', 'operators.%')->orderBy('id')->pluck('event')->all());

        $customer = Customer::create(['company_id' => $this->a->id, 'code' => 'C1', 'name' => 'Cliente', 'source' => 'import']);
        $this->putJson('/api/v1/customers/assignment', ['company_id' => $this->a->id, 'code' => 'C1', 'seller_id' => null, 'operator_id' => $id])
            ->assertUnprocessable()->assertJsonValidationErrors(['operator_id']); // inactivo
        $this->assertNull($customer->fresh()->operator_id);
    }

    public function test_customer_data_can_be_edited_and_is_audited(): void
    {
        Customer::create(['company_id' => $this->a->id, 'branch_id' => $this->centro->id, 'code' => 'C1', 'name' => 'Cliente', 'source' => 'sales']);
        $payload = ['company_id' => $this->a->id, 'code' => 'C1', 'name' => 'Taller Pérez', 'trade_name' => 'Pérez Motors', 'phone' => '5555-1234', 'email' => 'a@b.com', 'branch_id' => $this->centro->id];

        $this->putJson('/api/v1/customers/data', $payload)->assertOk()->assertJsonPath('data.name', 'Taller Pérez');
        $c = Customer::where('code', 'C1')->sole();
        $this->assertSame(['manual', 'Pérez Motors', null], [$c->source, $c->trade_name, $c->address]);
        $this->assertSame(1, AuditLog::where('event', 'customers.updated')->count());

        $this->putJson('/api/v1/customers/data', $payload)->assertOk();     // sin cambios: no audita
        $this->assertSame(1, AuditLog::where('event', 'customers.updated')->count());

        $this->putJson('/api/v1/customers/data', [...$payload, 'email' => 'mal', 'phone' => 'llamar', 'name' => ''])
            ->assertUnprocessable()->assertJsonValidationErrors(['email', 'phone', 'name']);
        $this->putJson('/api/v1/customers/data', [...$payload, 'code' => 'NOPE'])->assertNotFound();
        $this->putJson('/api/v1/customers/data', [...$payload, 'phone' => null, 'trade_name' => null])->assertOk();
        $this->assertNull(Customer::where('code', 'C1')->value('phone'));
    }

    public function test_maintenance_needs_manage_permission_and_full_company_access(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $this->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['customers.view']])->assertOk();
        $user->unsetRelations();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/customers/operators', ['company_id' => $this->a->id, 'name' => 'X'])->assertForbidden();
        $this->putJson('/api/v1/customers/data', ['company_id' => $this->a->id, 'code' => 'C1', 'name' => 'X'])->assertForbidden();
        $this->getJson('/api/v1/customers/seller-users')->assertForbidden();

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['customers.view', 'customers.manage']])->assertOk();
        $user->unsetRelations();
        $user->branches()->attach($this->centro);        // limitado a una sucursal: no administra la compañía
        app(DataScope::class)->forget();
        $this->actingAs($user, 'sanctum')->postJson('/api/v1/customers/operators', ['company_id' => $this->a->id, 'name' => 'X'])->assertUnprocessable()->assertJsonValidationErrors(['company_id']);
    }
}
