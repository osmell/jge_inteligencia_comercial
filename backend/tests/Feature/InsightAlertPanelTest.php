<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\InsightAlert;
use App\Models\InsightRun;
use App\Models\Role;
use App\Models\Seller;
use App\Models\User;
use App\Services\FollowUpAgenda;
use App\Services\FollowUpKpis;
use App\Services\InsightRunLogger;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InsightAlertPanelTest extends TestCase
{
    use RefreshDatabase;

    private Company $a;

    private User $admin;

    private User $seller;

    private Seller $mine;

    private Seller $other;

    private InsightRun $run;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 12:00:00');
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $this->a = Company::factory()->create(['code' => 'A', 'name' => 'Alfa']);
        Branch::factory()->create(['company_id' => $this->a->id]);
        $this->seller = User::factory()->role(Role::VENDEDOR)->create();
        $this->seller->companies()->attach($this->a->id);
        $this->mine = Seller::create(['company_id' => $this->a->id, 'name' => 'Vera', 'normalized_name' => 'vera', 'user_id' => $this->seller->id]);
        $this->other = Seller::create(['company_id' => $this->a->id, 'name' => 'Otro', 'normalized_name' => 'otro']);
        foreach (['C1' => $this->mine, 'C2' => $this->other] as $code => $s) {
            Customer::create(['company_id' => $this->a->id, 'code' => $code, 'name' => "Cliente $code", 'source' => 'sales', 'seller_id' => $s->id]);
        }
        $this->run = app(InsightRunLogger::class)->record($this->a->id, 'inactivity', InsightRun::OK, null, null);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$this->seller->role_id, ['permissions' => ['insights.view', 'interactions.manage', 'customers.view']])->assertOk();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function alert(string $code, string $type = InsightAlert::INACTIVITY_RISK, string $severity = 'high'): InsightAlert
    {
        return InsightAlert::create([
            'company_id' => $this->a->id, 'insight_run_id' => $this->run->id, 'customer_code' => $code, 'customer_name' => "Cliente $code", 'type' => $type, 'severity' => $severity, 'dedupe_key' => "$type:$code",
            'facts' => ['x' => 1], 'explanation' => 'Lleva 38 días sin comprar.', 'status' => 'open', 'first_detected_at' => now(), 'last_detected_at' => now(),
        ]);
    }

    public function test_an_alert_is_closed_with_a_reason_recording_who_and_when(): void
    {
        $alert = $this->alert('C1');
        $this->actingAs($this->seller, 'sanctum');

        $this->postJson("/api/v1/insights/alerts/{$alert->id}/close", [])->assertUnprocessable()->assertJsonValidationErrors(['reason']);
        $this->postJson("/api/v1/insights/alerts/{$alert->id}/close", ['reason' => 'inventado'])->assertUnprocessable();
        $this->postJson("/api/v1/insights/alerts/{$alert->id}/close", ['reason' => 'other'])->assertUnprocessable()->assertJsonValidationErrors(['note']);

        $this->postJson("/api/v1/insights/alerts/{$alert->id}/close", ['reason' => 'contacted', 'note' => 'Le escribí por WhatsApp'])->assertOk()
            ->assertJsonPath('data.status', 'closed')->assertJsonPath('data.closed.reason', 'contacted')->assertJsonPath('data.closed.by', $this->seller->name);
        $alert->refresh();
        $this->assertSame(['closed', $this->seller->id, 'contacted'], [$alert->status, $alert->closed_by, $alert->close_reason]);
        $this->assertNotNull($alert->closed_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'insights.alert_closed', 'user_id' => $this->seller->id]);

        $this->postJson("/api/v1/insights/alerts/{$alert->id}/close", ['reason' => 'resolved'])->assertUnprocessable();       // ya cerrada
        $url = '/api/v1/insights/alerts?company_id='.$this->a->id;
        $this->assertCount(0, $this->getJson($url)->json('data'));
        $closed = $this->getJson($url.'&status=closed')->json('data.0');
        $this->assertSame(['contacted', 'Le escribí por WhatsApp', $this->seller->name], [$closed['closed']['reason'], $closed['closed']['note'], $closed['closed']['by']]);
    }

    public function test_only_people_who_manage_follow_up_can_close_and_nobody_reaches_other_sellers_customers(): void
    {
        $mine = $this->alert('C1');
        $theirs = $this->alert('C2');

        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/insights/alerts/{$theirs->id}/close", ['reason' => 'resolved'])->assertNotFound();
        $this->postJson("/api/v1/insights/alerts/{$theirs->id}/follow-up", ['next_action' => 'Llamar', 'next_action_at' => '2026-10-25 09:00:00'])->assertNotFound();

        $viewer = User::factory()->role(Role::SUPERVISOR_VENTAS)->create();
        $viewer->companies()->attach($this->a->id);
        $this->actingAs($this->admin, 'sanctum')->putJson('/api/v1/admin/role-permissions/'.$viewer->role_id, ['permissions' => ['insights.view']])->assertOk();
        $this->actingAs($viewer, 'sanctum')->postJson("/api/v1/insights/alerts/{$mine->id}/close", ['reason' => 'resolved'])->assertForbidden();
        $this->postJson("/api/v1/insights/alerts/{$mine->id}/follow-up", ['next_action' => 'x', 'next_action_at' => '2026-10-25 09:00:00'])->assertForbidden();
    }

    public function test_a_follow_up_action_is_created_on_request_planned_and_not_counted_as_a_contact(): void
    {
        $alert = $this->alert('C1');
        $this->actingAs($this->seller, 'sanctum');

        $this->postJson("/api/v1/insights/alerts/{$alert->id}/follow-up", ['next_action' => 'Llamar para ofrecer reposición', 'next_action_at' => '2026-10-19 09:00:00'])->assertUnprocessable()->assertJsonValidationErrors(['next_action_at']);
        $this->postJson("/api/v1/insights/alerts/{$alert->id}/follow-up", ['next_action_at' => '2026-10-25 09:00:00'])->assertUnprocessable()->assertJsonValidationErrors(['next_action']);
        $this->postJson("/api/v1/insights/alerts/{$alert->id}/follow-up", ['next_action' => 'Llamar para ofrecer reposición', 'next_action_at' => '2026-10-25 09:00:00'])->assertCreated();

        $i = CustomerInteraction::sole();
        $this->assertSame([CustomerInteraction::PLANNED, 'other', $this->seller->id, $alert->id, 'Llamar para ofrecer reposición'], [$i->outcome, $i->channel, $i->user_id, $i->insight_alert_id, $i->next_action]);
        $this->assertStringContainsString('Acción planeada desde una recomendación', (string) $i->response);
        $this->assertSame($i->id, $alert->refresh()->follow_up_interaction_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'insights.alert_follow_up_created']);
        $this->postJson("/api/v1/insights/alerts/{$alert->id}/follow-up", ['next_action' => 'Otra', 'next_action_at' => '2026-10-26 09:00:00'])->assertUnprocessable();   // una sola por alerta
        $this->assertTrue($this->getJson('/api/v1/insights/alerts?company_id='.$this->a->id)->json('data.0.follow_up'));

        // Aparece en la agenda como acción próxima…
        $agenda = app(FollowUpAgenda::class)->build($this->seller, ['horizon_days' => 7, 'no_contact_days' => 30]);
        $this->assertSame(['Llamar para ofrecer reposición'], array_column($agenda['upcoming'], 'next_action'));
        // …pero no cuenta como un contacto en los indicadores.
        $kpis = app(FollowUpKpis::class)->build($this->admin, CarbonImmutable::parse('2026-10-01'), CarbonImmutable::parse('2026-10-31'), []);
        $this->assertSame(0, $kpis['contacts']['total']);
    }

    public function test_no_action_is_created_for_a_customer_who_asked_not_to_be_contacted(): void
    {
        $alert = $this->alert('C1');
        Customer::where('code', 'C1')->first()->forceFill(['do_not_contact' => true, 'do_not_contact_reason' => 'Lo pidió'])->save();

        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/insights/alerts/{$alert->id}/follow-up", ['next_action' => 'Llamar', 'next_action_at' => '2026-10-25 09:00:00'])
            ->assertUnprocessable()->assertJsonPath('message', 'El cliente pidió no recibir comunicaciones: no se programan acciones de contacto.');
        $this->assertSame(0, CustomerInteraction::count());
    }

    public function test_a_planned_action_does_not_count_as_the_last_contact_when_looking_for_customers_to_reactivate(): void
    {
        $alert = $this->alert('C1');
        $this->actingAs($this->seller, 'sanctum')->postJson("/api/v1/insights/alerts/{$alert->id}/follow-up", ['next_action' => 'Llamar', 'next_action_at' => '2026-10-25 09:00:00'])->assertCreated();

        $this->assertNull(DB::table('customer_interactions')->where('outcome', '!=', CustomerInteraction::PLANNED)->max('occurred_at'));
    }

    public function test_the_list_filters_by_seller_and_includes_every_recommendation_type(): void
    {
        $this->alert('C1', InsightAlert::CROSS_SELL, 'low');
        $this->alert('C2', InsightAlert::RFM_AT_RISK, 'medium');
        $url = '/api/v1/insights/alerts?company_id='.$this->a->id;

        $all = $this->actingAs($this->admin, 'sanctum')->getJson($url)->assertOk();
        $this->assertSame(['rfm_at_risk', 'cross_sell'], array_column($all->json('data'), 'type'));       // la más grave primero
        $this->assertSame(1, $all->json('meta.counts.cross_sell'));
        $this->assertSame(['C1'], array_column($this->getJson($url.'&seller_id='.$this->mine->id)->json('data'), 'code'));
        $this->assertSame(['C2'], array_column($this->getJson($url.'&seller_id='.$this->other->id)->json('data'), 'code'));
        $this->assertSame(['cross_sell'], array_column($this->getJson($url.'&type=cross_sell')->json('data'), 'type'));
    }
}
