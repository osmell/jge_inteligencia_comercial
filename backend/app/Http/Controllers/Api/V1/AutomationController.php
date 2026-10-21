<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use App\Services\AutomationCatalog;
use App\Services\AutomationRunner;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Administración de las automatizaciones: catálogo, estado, parámetros, interruptor general, ejecución manual y registro. */
class AutomationController extends Controller
{
    public function __construct(private readonly AutomationCatalog $catalog, private readonly AutomationRunner $runner) {}

    public function index(): JsonResponse
    {
        return response()->json(['data' => $this->catalog->describe(), 'meta' => ['master_enabled' => $this->catalog->masterEnabled(), 'window' => $this->catalog->window(), 'retry_minutes' => config('automations.retry_minutes')]]);
    }

    public function update(Request $request, string $automation): JsonResponse
    {
        abort_unless($this->catalog->exists($automation), 404, 'Automatización no encontrada.');
        $data = $request->validate(['enabled' => ['nullable', 'boolean'], 'params' => ['nullable', 'array']]);
        $this->catalog->update($request->user(), $automation, $data['enabled'] ?? null, $data['params'] ?? []);

        return $this->index();
    }

    public function settings(Request $request): JsonResponse
    {
        $data = $request->validate(['master_enabled' => ['nullable', 'boolean'], 'window' => ['nullable', 'array'], 'window.start' => ['required_with:window', 'integer', 'min:0', 'max:23'], 'window.end' => ['required_with:window', 'integer', 'min:1', 'max:24']]);
        if (array_key_exists('master_enabled', $data) && $data['master_enabled'] !== null) {
            $this->catalog->setMaster($request->user(), (bool) $data['master_enabled']);
        }
        if (isset($data['window'])) {
            $this->catalog->setWindow($request->user(), (int) $data['window']['start'], (int) $data['window']['end']);
        }

        return $this->index();
    }

    public function run(Request $request, string $automation): JsonResponse
    {
        $run = $this->runner->runNow($automation, $request->user());

        return response()->json(['data' => $this->present($run)], 201);
    }

    public function runs(Request $request): JsonResponse
    {
        $data = $request->validate(['automation' => ['nullable', Rule::in(array_keys($this->catalog->definitions()))], 'status' => ['nullable', Rule::in([AutomationRun::RUNNING, AutomationRun::OK, AutomationRun::FAILED])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $rows = AutomationRun::query()->when(! empty($data['automation']), fn (Builder $q) => $q->where('automation', $data['automation']))->when(! empty($data['status']), fn (Builder $q) => $q->where('status', $data['status']))
            ->orderByDesc('started_at')->orderByDesc('id')->offset(($page - 1) * 25)->limit(26)->get();

        return response()->json(['data' => $rows->take(25)->map(fn (AutomationRun $r) => $this->present($r))->values()->all(), 'meta' => ['page' => $page, 'has_more' => $rows->count() > 25]]);
    }

    /** @return array<string, mixed> */
    private function present(AutomationRun $r): array
    {
        return [
            'id' => $r->id, 'automation' => $r->automation, 'label' => $this->catalog->definitions()[$r->automation]['label'] ?? $r->automation, 'trigger' => $r->trigger, 'status' => $r->status, 'attempt' => $r->attempt,
            'started_at' => $r->started_at->toIso8601String(), 'finished_at' => $r->finished_at?->toIso8601String(), 'next_retry_at' => $r->next_retry_at?->toIso8601String(), 'result' => $r->result, 'error' => $r->error,
        ];
    }
}
