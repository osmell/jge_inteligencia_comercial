<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\WebhookDelivery;
use App\Services\WebhookService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Configuración del webhook de salida y registro de envíos. El secreto se recibe pero nunca se devuelve. */
class WebhookController extends Controller
{
    public function __construct(private readonly WebhookService $webhook) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->webhook->describe()]);
    }

    public function update(Request $request): JsonResponse
    {
        $types = array_column($this->webhook->available(), 'type');
        $data = $request->validate([
            'enabled' => ['required', 'boolean'], 'url' => ['nullable', 'url:http,https', 'max:500'], 'secret' => ['nullable', 'string', 'min:16', 'max:200'],
            'events' => ['nullable', 'array'], 'events.*' => ['string', Rule::in($types)],
        ], ['secret.min' => 'El secreto debe tener al menos 16 caracteres.']);
        $this->webhook->save($request->user(), $data);

        return $this->show();
    }

    public function test(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->webhook->sendTest($request->user()))], 201);
    }

    public function log(Request $request): JsonResponse
    {
        $data = $request->validate(['status' => ['nullable', Rule::in([WebhookDelivery::PENDING, WebhookDelivery::SENDING, WebhookDelivery::SENT, WebhookDelivery::FAILED])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $page = max(1, (int) ($data['page'] ?? 1));
        $rows = WebhookDelivery::query()->when(! empty($data['status']), fn (Builder $q) => $q->where('status', $data['status']))->orderByDesc('id')->offset(($page - 1) * 25)->limit(26)->get();

        return response()->json(['data' => $rows->take(25)->map(fn (WebhookDelivery $d) => $this->present($d))->values()->all(), 'meta' => ['page' => $page, 'has_more' => $rows->count() > 25]]);
    }

    /** @return array<string, mixed> */
    private function present(WebhookDelivery $d): array
    {
        return ['id' => $d->id, 'event' => $d->event, 'status' => $d->status, 'attempt' => $d->attempt, 'http_status' => $d->http_status, 'error' => $d->error, 'created_at' => $d->created_at->toIso8601String(), 'sent_at' => $d->sent_at?->toIso8601String()];
    }
}
