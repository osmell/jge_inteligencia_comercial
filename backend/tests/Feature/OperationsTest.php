<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

class OperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_api_response_carries_a_request_id(): void
    {
        $response = $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        $this->assertTrue(Str::isUuid((string) $response->headers->get('X-Request-Id')));
    }

    public function test_a_valid_request_id_from_the_caller_is_kept_and_an_invalid_one_is_replaced(): void
    {
        $this->getJson('/api/v1/auth/me', ['X-Request-Id' => 'traza-2026-abc123'])->assertHeader('X-Request-Id', 'traza-2026-abc123');

        $replaced = $this->getJson('/api/v1/auth/me', ['X-Request-Id' => "malo\nvalor"])->headers->get('X-Request-Id');
        $this->assertTrue(Str::isUuid((string) $replaced));
    }

    public function test_the_request_id_is_added_to_the_log_context(): void
    {
        $this->getJson('/api/v1/auth/me', ['X-Request-Id' => 'traza-contexto-1']);

        $this->assertSame('traza-contexto-1', Log::sharedContext()['request_id'] ?? null);
    }

    public function test_a_failed_queue_job_is_logged_with_context(): void
    {
        Log::spy();
        $job = \Mockery::mock(Job::class);
        $job->shouldReceive('resolveName')->andReturn('App\\Jobs\\SendWhatsappMessage');
        $job->shouldReceive('getQueue')->andReturn('default');
        $job->shouldReceive('attempts')->andReturn(4);

        Event::dispatch(new JobFailed('redis', $job, new RuntimeException('Meta no respondió')));

        Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context) => $message === 'queue.job_failed' && $context['job'] === 'App\\Jobs\\SendWhatsappMessage' && $context['attempts'] === 4 && $context['exception'] === 'Meta no respondió')->once();
    }

    public function test_ops_status_is_green_when_nothing_failed(): void
    {
        $this->artisan('ops:status')->expectsOutputToContain('Todo en orden.')->assertExitCode(0);
    }

    public function test_ops_status_fails_with_failed_jobs_unless_tolerated(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);

        $this->artisan('ops:status')->expectsOutputToContain('Hay indicadores fuera de su umbral.')->assertExitCode(1);
        $this->artisan('ops:status', ['--max-failed-jobs' => 1])->assertExitCode(0);
    }

    public function test_ops_status_can_print_json_for_a_monitor(): void
    {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
        $this->artisan('ops:status', ['--json' => true])->assertExitCode(1);

        $exit = Artisan::call('ops:status', ['--json' => true]);
        $data = json_decode(trim(Artisan::output()), true);

        $this->assertSame(1, $exit);
        $this->assertFalse($data['ok']);
        $this->assertSame(1, $data['checks']['failed_jobs']['value']);
        $this->assertTrue($data['checks']['database']['ok']);
    }
}
