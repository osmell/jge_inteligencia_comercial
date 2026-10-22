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

class CustomerActivityTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private Branch $branch;

    private User $admin;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A']);
        $this->branch = Branch::factory()->create(['company_id' => $this->a->id]);

        // El historial de la compañía empieza el 2026-01-01 (compra de HIST).
        $this->buy('HIST', ['2026-01-01', '2026-09-20', '2026-10-10']);        // 10 días → activo (fue cliente desde el inicio, no «nuevo»)
        $this->buy('NEW', ['2026-10-05']);                          // primera compra hace 15 días → nuevo
        $this->buy('BACK', ['2026-03-01', '2026-10-15']);           // volvió tras >30 días sin comprar → recuperado
        $this->buy('ACT', ['2026-08-01', '2026-09-20', '2026-10-01']); // hace 19 días → activo
        $this->buy('INA', ['2026-06-01', '2026-08-31']);            // hace 50 días → inactivo
        $this->buy('LOST', ['2026-02-01', '2026-06-01']);           // hace 141 días → perdido
        Customer::create(['company_id' => $this->a->id, 'code' => 'NONE', 'name' => 'Cliente NONE', 'source' => 'import']);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param list<string> $dates */
    private function buy(string $code, array $dates): void
    {
        Customer::create(['company_id' => $this->a->id, 'code' => $code, 'name' => "Cliente $code", 'source' => 'import']);
        foreach ($dates as $date) {
            $doc = SalesDocument::factory()->create(['company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'series' => 'S', 'number' => 'N'.++$this->seq, 'issued_on' => $date, 'customer_code' => $code, 'lines_count' => 1]);
            SalesLine::create([
                'sales_document_id' => $doc->id, 'company_id' => $this->a->id, 'branch_id' => $this->branch->id, 'issued_on' => $date, 'line_number' => 1,
                'product_code' => 'P', 'product_name' => 'P', 'category_name' => 'Cat', 'units' => 1, 'unit_cost' => 0, 'line_cost_total' => 0, 'unit_price' => 0,
                'net_total' => 10, 'tax_total' => 0, 'sale_plus_tax' => 10, 'grand_total' => 10,
            ]);
        }
    }

    /** @return array<string, string> */
    private function statuses(array $params = []): array
    {
        return collect($this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/customers?'.http_build_query($params))->assertOk()->json('data'))->pluck('activity', 'code')->all();
    }

    public function test_default_rules_classify_each_customer(): void
    {
        $this->assertEquals(
            ['HIST' => 'active', 'NEW' => 'new', 'BACK' => 'recovered', 'ACT' => 'active', 'INA' => 'inactive', 'LOST' => 'lost', 'NONE' => 'no_sales'],
            $this->statuses(),
        );
    }

    public function test_profile_includes_status_and_rules_and_filter_selects_customers(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $profile = $this->getJson('/api/v1/customers/profile?'.http_build_query(['code' => 'INA', 'company_id' => $this->a->id]))->json('data.activity');
        $this->assertSame(['status' => 'inactive', 'inactive_days' => 30, 'lost_days' => 90], $profile);

        $this->assertSame(['LOST'], array_keys($this->statuses(['status' => 'lost'])));
        $this->assertEqualsCanonicalizing(['HIST', 'ACT'], array_keys($this->statuses(['status' => 'active'])));
        $this->assertSame(['NONE'], array_keys($this->statuses(['status' => 'no_sales'])));
        $this->getJson('/api/v1/customers?status=raro')->assertUnprocessable();
    }

    public function test_rules_can_be_changed_validated_and_audited(): void
    {
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/customers/activity-rules', ['inactive_days' => 60, 'lost_days' => 60])
            ->assertUnprocessable()->assertJsonValidationErrors(['lost_days']);
        $this->putJson('/api/v1/customers/activity-rules', ['inactive_days' => 60, 'lost_days' => 120])->assertOk()->assertJsonPath('data.inactive_days', 60);
        $this->putJson('/api/v1/customers/activity-rules', ['inactive_days' => 60, 'lost_days' => 120])->assertOk();
        $this->getJson('/api/v1/customers/activity-rules')->assertOk()->assertJsonPath('data.lost_days', 120);
        $this->assertSame(1, AuditLog::where('event', 'customers.activity_rules_updated')->count());

        // Con 60/120 días: INA (50 días; volvió tras 91 sin comprar) pasa a recuperado y LOST (141) sigue perdido.
        $statuses = $this->statuses();
        $this->assertSame('recovered', $statuses['INA']);
        $this->assertSame('lost', $statuses['LOST']);
    }

    public function test_changing_rules_needs_the_manage_permission(): void
    {
        $user = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $user->companies()->attach($this->a);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$user->role_id, ['permissions' => ['customers.view']])->assertOk();
        $user->unsetRelations();

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/customers/activity-rules')->assertOk();
        $this->putJson('/api/v1/customers/activity-rules', ['inactive_days' => 10, 'lost_days' => 20])->assertForbidden();
    }
}
