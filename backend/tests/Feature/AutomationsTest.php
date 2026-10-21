<?php

namespace Tests\Feature;

use App\Automations\InsightsRefreshHandler;
use App\Automations\PruneNotificationsHandler;
use App\Models\AutomationRun;
use App\Models\Role;
use App\Models\User;
use App\Services\AutomationCatalog;
use App\Services\AutomationRunner;
use Carbon\CarbonImmutable;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Tests\Support\FakeAutomationHandler;
use Tests\TestCase;

class AutomationsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private AutomationRunner $runner;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-21 08:00:00');                       // miércoles
        $this->seed(RolePermissionSeeder::class);
        $this->admin = User::factory()->role(Role::ADMINISTRADOR)->create();
        $hour = ['hour' => ['label' => 'Hora', 'unit' => 'hora', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => '']];
        config(['automations.automations' => [
            'avisos' => ['label' => 'Avisos de prueba', 'description' => 'd', 'frequency' => 'daily', 'respect_window' => true, 'handler' => FakeAutomationHandler::class, 'default_enabled' => true, 'params' => $hour],
            'mantenimiento' => ['label' => 'Mantenimiento', 'description' => 'd', 'frequency' => 'daily', 'respect_window' => false, 'handler' => FakeAutomationHandler::class, 'default_enabled' => true, 'params' => ['hour' => ['label' => 'Hora', 'unit' => 'hora', 'default' => 2, 'min' => 0, 'max' => 23, 'help' => '']]],
            'semanal' => ['label' => 'Semanal', 'description' => 'd', 'frequency' => 'weekly', 'respect_window' => false, 'handler' => FakeAutomationHandler::class, 'default_enabled' => true, 'params' => $hour],
            'pronto' => ['label' => 'En preparación', 'description' => 'd', 'frequency' => 'daily', 'respect_window' => true, 'handler' => null, 'default_enabled' => true, 'params' => $hour],
        ]]);
        FakeAutomationHandler::reset();
        $this->runner = app(AutomationRunner::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tickAt(string $when): array
    {
        return $this->runner->tick(CarbonImmutable::parse($when));
    }

    private function runs(string $automation): Collection
    {
        return AutomationRun::where('automation', $automation)->orderBy('id')->get();
    }

    public function test_a_daily_automation_runs_once_per_day_at_or_after_its_hour(): void
    {
        $this->assertSame(2, $this->tickAt('2026-10-21 03:00:00')['started']);      // a las 3 ya tocan el mantenimiento (2 a. m.) y la semanal (desde el lunes)
        $this->assertCount(0, $this->runs('avisos'));
        $this->assertCount(1, $this->runs('mantenimiento'));

        $this->assertSame(1, $this->tickAt('2026-10-21 08:00:00')['started']);      // y a las 8, los avisos (7 a. m.)
        $this->tickAt('2026-10-21 08:05:00');                              // el programador corre otra vez: no se repite
        $this->tickAt('2026-10-21 15:00:00');
        $this->assertCount(1, $this->runs('avisos'));
        $this->assertSame(['ok', 'schedule', '2026-10-21'], [$this->runs('avisos')->first()->status, $this->runs('avisos')->first()->trigger, $this->runs('avisos')->first()->period_key]);
        $this->assertEquals(['notified' => 3, 'hour' => 7], $this->runs('avisos')->first()->result);

        $this->tickAt('2026-10-22 07:00:00');                              // al día siguiente vuelve a correr
        $this->assertCount(2, $this->runs('avisos'));
        $this->assertSame('2026-10-22', $this->runs('avisos')->last()->period_key);
    }

    public function test_notifying_automations_respect_the_window_but_maintenance_does_not(): void
    {
        $this->tickAt('2026-10-21 20:00:00');                              // después de las 19:00
        $this->assertCount(0, $this->runs('avisos'));
        $this->assertCount(1, $this->runs('mantenimiento'));

        $this->tickAt('2026-10-25 09:00:00');                              // domingo
        $this->assertCount(0, $this->runs('avisos'));
        $this->assertCount(1, $this->runs('mantenimiento')->where('period_key', '2026-10-25'));

        $this->tickAt('2026-10-26 07:00:00');                              // lunes en la ventana
        $this->assertCount(1, $this->runs('avisos'));
    }

    public function test_the_weekly_automation_runs_once_per_week(): void
    {
        $this->tickAt('2026-10-21 08:00:00');
        $this->tickAt('2026-10-23 08:00:00');
        $this->assertCount(1, $this->runs('semanal'));
        $this->assertSame('2026-W43', $this->runs('semanal')->first()->period_key);

        $this->tickAt('2026-10-26 08:00:00');                              // lunes: semana nueva
        $this->assertCount(2, $this->runs('semanal'));
    }

    public function test_a_paused_automation_and_the_master_switch_stop_everything(): void
    {
        app(AutomationCatalog::class)->update($this->admin, 'avisos', false, []);
        $this->tickAt('2026-10-21 08:00:00');
        $this->assertCount(0, $this->runs('avisos'));
        $this->assertCount(1, $this->runs('mantenimiento'));

        app(AutomationCatalog::class)->setMaster($this->admin, false);
        $this->assertSame(['started' => 0, 'retried' => 0, 'interrupted' => 0], $this->tickAt('2026-10-22 08:00:00'));
        $this->assertCount(1, $this->runs('mantenimiento'));
        $this->assertDatabaseHas('audit_logs', ['event' => 'automations.master_changed']);
    }

    public function test_an_automation_in_preparation_never_runs(): void
    {
        $this->tickAt('2026-10-21 08:00:00');

        $this->assertCount(0, $this->runs('pronto'));
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/automations/pronto/run')->assertUnprocessable();
    }

    public function test_a_failed_run_is_retried_with_backoff_without_duplicating_and_gives_up_after_three_attempts(): void
    {
        FakeAutomationHandler::reset(failFirst: 1);
        $this->tickAt('2026-10-21 08:00:00');
        $run = $this->runs('avisos')->first();
        $this->assertSame(['failed', 1, 'El servicio no respondió'], [$run->status, $run->attempt, $run->error]);
        $this->assertEquals(CarbonImmutable::parse('2026-10-21 08:05:00'), $run->next_retry_at);

        $this->tickAt('2026-10-21 08:03:00');                              // todavía no toca el reintento
        $this->assertSame(1, $this->runs('avisos')->first()->attempt);

        $this->assertSame(1, $this->tickAt('2026-10-21 08:06:00')['retried']);
        $run->refresh();
        $this->assertSame(['ok', 2, null], [$run->status, $run->attempt, $run->next_retry_at]);
        $this->assertCount(1, $this->runs('avisos'));                      // misma fila: sin duplicados

        // Si siempre falla: 3 intentos y no más.
        FakeAutomationHandler::reset(failFirst: 99);
        $this->tickAt('2026-10-22 08:00:00');
        $this->tickAt('2026-10-22 08:06:00');                              // intento 2, espera de 15 min
        $this->tickAt('2026-10-22 08:30:00');                              // intento 3
        $this->tickAt('2026-10-22 10:00:00');
        $last = $this->runs('avisos')->last();
        $this->assertSame(['failed', 3, null], [$last->status, $last->attempt, $last->next_retry_at]);
    }

    public function test_a_run_that_never_finished_is_marked_as_interrupted_and_retried(): void
    {
        AutomationRun::create(['automation' => 'avisos', 'trigger' => 'schedule', 'period_key' => '2026-10-21', 'status' => 'running', 'attempt' => 1, 'started_at' => '2026-10-21 07:00:00']);

        $r = $this->tickAt('2026-10-21 08:00:00');

        $this->assertSame(1, $r['interrupted']);
        $run = AutomationRun::where('period_key', '2026-10-21')->where('automation', 'avisos')->sole();
        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('se interrumpió', (string) $run->error);
        $this->tickAt('2026-10-21 08:06:00');
        $this->assertSame(['ok', 2], [$run->refresh()->status, $run->attempt]);
    }

    public function test_changing_the_hour_changes_when_it_runs(): void
    {
        app(AutomationCatalog::class)->update($this->admin, 'avisos', null, ['hour' => 10]);

        $this->tickAt('2026-10-21 09:00:00');
        $this->assertCount(0, $this->runs('avisos'));
        $this->tickAt('2026-10-21 10:00:00');
        $this->assertCount(1, $this->runs('avisos'));
        $this->assertSame(10, $this->runs('avisos')->first()->result['hour']);
    }

    public function test_the_api_manages_the_catalog_and_everything_is_audited(): void
    {
        $this->actingAs($this->admin, 'sanctum');
        $list = $this->getJson('/api/v1/automations')->assertOk();
        $this->assertSame(['avisos', 'mantenimiento', 'semanal', 'pronto'], array_column($list->json('data'), 'key'));
        $this->assertSame([true, true, ['start' => 7, 'end' => 19]], [$list->json('meta.master_enabled'), $list->json('data.0.enabled'), $list->json('meta.window')]);
        $this->assertSame([false, true], [$list->json('data.3.available'), $list->json('data.0.available')]);

        $this->putJson('/api/v1/automations/avisos', ['enabled' => false, 'params' => ['hour' => 9]])->assertOk()->assertJsonPath('data.0.enabled', false)->assertJsonPath('data.0.params.0.value', 9);
        $log = DB::table('audit_logs')->where('event', 'automations.updated')->sole();
        $this->assertSame([true, 7], [json_decode($log->old_values, true)['enabled'], json_decode($log->old_values, true)['params']['hour']]);
        $this->assertSame([false, 9], [json_decode($log->new_values, true)['enabled'], json_decode($log->new_values, true)['params']['hour']]);

        $this->putJson('/api/v1/automations/avisos', ['params' => ['hour' => 30]])->assertUnprocessable()->assertJsonValidationErrors(['params.hour']);
        $this->putJson('/api/v1/automations/avisos', ['params' => ['inventado' => 1]])->assertUnprocessable();
        $this->putJson('/api/v1/automations/avisos', ['params' => ['hour' => 'tarde']])->assertUnprocessable();
        $this->putJson('/api/v1/automations/nada', ['enabled' => true])->assertNotFound();

        $this->putJson('/api/v1/automations/settings', ['window' => ['start' => 8, 'end' => 18], 'master_enabled' => false])->assertOk()->assertJsonPath('meta.window.end', 18)->assertJsonPath('meta.master_enabled', false);
        $this->putJson('/api/v1/automations/settings', ['window' => ['start' => 18, 'end' => 8]])->assertUnprocessable();
        $this->assertDatabaseHas('audit_logs', ['event' => 'automations.window_changed']);
    }

    public function test_a_manual_run_is_logged_with_who_ran_it_and_does_not_retry(): void
    {
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/automations/avisos/run')->assertCreated()->assertJsonPath('data.status', 'ok')->assertJsonPath('data.trigger', 'manual');
        $this->assertSame($this->admin->id, $this->runs('avisos')->first()->user_id);
        $this->assertDatabaseHas('audit_logs', ['event' => 'automations.run_manual']);

        FakeAutomationHandler::reset(failFirst: 5);
        $this->postJson('/api/v1/automations/avisos/run')->assertCreated()->assertJsonPath('data.status', 'failed')->assertJsonPath('data.error', 'El servicio no respondió')->assertJsonPath('data.next_retry_at', null);

        app(AutomationCatalog::class)->setMaster($this->admin, false);
        $this->postJson('/api/v1/automations/avisos/run')->assertUnprocessable();

        $runs = $this->getJson('/api/v1/automations/runs?automation=avisos')->assertOk()->json('data');
        $this->assertSame(['failed', 'ok'], array_column($runs, 'status'));
        $this->assertSame(['failed'], array_column($this->getJson('/api/v1/automations/runs?status=failed')->json('data'), 'status'));
        $this->getJson('/api/v1/automations/runs?automation=inventada')->assertUnprocessable();
    }

    public function test_only_people_with_the_permission_can_use_the_administration(): void
    {
        $viewer = User::factory()->role(Role::GERENTE_GENERAL)->create();
        $this->actingAs($viewer, 'sanctum');
        $this->getJson('/api/v1/automations')->assertForbidden();
        $this->putJson('/api/v1/automations/avisos', ['enabled' => false])->assertForbidden();
        $this->putJson('/api/v1/automations/settings', ['master_enabled' => false])->assertForbidden();
        $this->postJson('/api/v1/automations/avisos/run')->assertForbidden();
        $this->getJson('/api/v1/automations/runs')->assertForbidden();
    }

    public function test_the_real_catalog_has_a_working_handler_for_every_automation(): void
    {
        $catalog = require base_path('config/automations.php');
        config(['automations.automations' => $catalog['automations']]);

        $this->assertEquals(
            ['insights_refresh' => true, 'notifications_prune' => true, 'followup_due' => true, 'quotation_expiring' => true, 'promise_due' => true, 'credit_bucket' => true, 'inventory_low' => true, 'insight_high' => true, 'digest_daily' => true, 'digest_weekly' => true],
            collect(app(AutomationCatalog::class)->describe())->pluck('available', 'key')->all(),
        );
        $this->assertSame(['deleted' => 0], app(PruneNotificationsHandler::class)->run([]));
        $this->assertSame(['calculated', 'insufficient', 'companies'], array_keys(app(InsightsRefreshHandler::class)->run([])));
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/automations/notifications_prune/run')->assertCreated()->assertJsonPath('data.status', 'ok');
    }
}
