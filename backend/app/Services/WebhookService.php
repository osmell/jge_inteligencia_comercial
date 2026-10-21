<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserNotification;
use App\Models\WebhookDelivery;
use App\Models\WebhookSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Webhook de salida genérico: avisa a otro sistema (n8n u otro) que pasó algo. Solo notifica, no recibe órdenes. Cada envío lleva
 * una firma HMAC-SHA256 de «marca de tiempo.cuerpo» con el secreto configurado; un evento se envía una sola vez (clave de
 * deduplicación) y los fallos se reintentan con espera creciente antes de darse por perdidos. El secreto se guarda cifrado.
 */
class WebhookService
{
    public const MAX_ATTEMPTS = 3;

    private const BACKOFF = [10, 60];

    public function __construct(private readonly AuditLogger $audit, private readonly Notifier $notifier) {}

    /** @return array<string, mixed> */
    public function describe(): array
    {
        $s = WebhookSetting::current();

        return ['enabled' => $s->enabled, 'url' => $s->url, 'has_secret' => ! empty($s->secret), 'events' => $s->events ?? [], 'available_events' => $this->available(), 'ready' => $s->ready(), 'updated_at' => $s->updated_at?->toIso8601String()];
    }

    /** @return list<array{type: string, label: string}> */
    public function available(): array
    {
        return array_map(fn (string $type, array $d) => ['type' => $type, 'label' => $d['label']], array_keys($this->notifier->types()), $this->notifier->types());
    }

    /** @param array<string, mixed> $data  secreto vacío = conservar */
    public function save(User $user, array $data): void
    {
        $current = WebhookSetting::current();
        $before = $this->describe();
        if ($data['enabled'] && (empty($data['url']) || (($data['secret'] ?? '') === '' && empty($current->secret)))) {
            throw ValidationException::withMessages(['url' => 'Para activar el webhook indica la dirección y el secreto de firma.']);
        }
        $setting = $current->exists ? $current : new WebhookSetting(['id' => 1]);
        $setting->forceFill([
            'id' => 1, 'enabled' => (bool) $data['enabled'], 'url' => $data['url'] ?? null, 'secret' => ($data['secret'] ?? '') !== '' ? (string) $data['secret'] : $current->secret,
            'events' => array_values(array_unique($data['events'] ?? [])), 'updated_by' => $user->id, 'updated_at' => now(),
        ])->save();

        $after = $this->describe();
        $changed = array_keys(array_filter($after, fn ($v, $k) => ! in_array($k, ['updated_at', 'available_events'], true) && $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        $secret = ($data['secret'] ?? '') !== '';
        if ($changed !== [] || $secret) {
            $this->audit->record('webhook.settings_updated', $user, old: array_intersect_key($before, array_flip($changed)), new: array_intersect_key($after, array_flip($changed)) + ($secret ? ['secret' => 'cambiado'] : []));
        }
    }

    /** Pone en cola el evento de un aviso (una sola vez por tipo + clave del aviso) si el webhook está activo y ese tipo está elegido. */
    public function queueNotice(UserNotification $notice): ?WebhookDelivery
    {
        $s = WebhookSetting::current();
        if (! $s->ready() || ! in_array($notice->type, $s->events ?? [], true)) {
            return null;
        }
        $key = "{$notice->type}|{$notice->dedupe_key}";
        if (WebhookDelivery::query()->where('dedupe_key', $key)->exists()) {
            return null;
        }
        $payload = $this->payload($notice->type, ['title' => $notice->title, 'body' => $notice->body, 'severity' => $notice->severity, 'company_id' => $notice->company_id, 'url' => $notice->url === null ? null : rtrim((string) config('app.frontend_url'), '/').$notice->url]);

        return WebhookDelivery::create(['event' => $notice->type, 'dedupe_key' => $key, 'payload' => $payload, 'status' => WebhookDelivery::PENDING, 'created_at' => now(), 'next_attempt_at' => now()]);
    }

    /** Envío de prueba inmediato, registrado como cualquier otro. */
    public function sendTest(User $user): WebhookDelivery
    {
        $s = WebhookSetting::current();
        if (! $s->ready()) {
            throw ValidationException::withMessages(['url' => 'Activa el webhook con dirección y secreto antes de probarlo.']);
        }
        $d = WebhookDelivery::create([
            'event' => 'test', 'dedupe_key' => 'test|'.bin2hex(random_bytes(8)), 'payload' => $this->payload('test', ['title' => 'Prueba del webhook', 'body' => 'Si recibes esto, la conexión y la firma funcionan.']),
            'status' => WebhookDelivery::SENDING, 'attempt' => 1, 'created_at' => now(),
        ]);
        $this->deliver($d, $s, retry: false);
        $this->audit->record('webhook.test_sent', $user, new: ['status' => $d->status]);

        return $d->refresh();
    }

    /**
     * Envía los pendientes que ya les toca, con reintentos.
     *
     * @return array{sent: int, failed: int, retry: int, stale: int}
     */
    public function dispatchPending(int $limit = 100): array
    {
        $result = ['sent' => 0, 'failed' => 0, 'retry' => 0, 'stale' => 0];
        // Un envío que quedó «en curso» no se reenvía solo (podría haber llegado): se marca para revisión.
        $result['stale'] = WebhookDelivery::query()->where('status', WebhookDelivery::SENDING)->where('created_at', '<', now()->subMinutes(30))
            ->update(['status' => WebhookDelivery::FAILED, 'error' => 'No se confirmó el envío; revisa el registro antes de reenviar.']);
        $s = WebhookSetting::current();
        if (! $s->ready()) {
            return $result;
        }
        foreach (WebhookDelivery::query()->where('status', WebhookDelivery::PENDING)->where('next_attempt_at', '<=', now())->orderBy('id')->limit($limit)->get() as $d) {
            $d->forceFill(['status' => WebhookDelivery::SENDING, 'attempt' => $d->attempt + 1])->save();
            $this->deliver($d, $s, retry: true);
            $d->refresh();
            $result[$d->status === WebhookDelivery::SENT ? 'sent' : ($d->status === WebhookDelivery::PENDING ? 'retry' : 'failed')]++;
        }

        return $result;
    }

    private function deliver(WebhookDelivery $d, WebhookSetting $s, bool $retry): void
    {
        $time = (string) now()->timestamp;
        try {
            $r = Http::timeout(15)->withHeaders([
                'Content-Type' => 'application/json', 'X-JGE-Event' => $d->event, 'X-JGE-Delivery' => (string) $d->id, 'X-JGE-Timestamp' => $time,
                'X-JGE-Signature' => 'sha256='.hash_hmac('sha256', $time.'.'.$d->payload, (string) $s->secret),
            ])->withBody($d->payload, 'application/json')->post((string) $s->url);
            if ($r->successful()) {
                $d->forceFill(['status' => WebhookDelivery::SENT, 'http_status' => $r->status(), 'sent_at' => now(), 'error' => null, 'next_attempt_at' => null])->save();

                return;
            }
            $this->failed($d, $retry, 'El destino respondió '.$r->status().'.', $r->status());
        } catch (Throwable $e) {
            $this->failed($d, $retry, mb_substr($e->getMessage(), 0, 900), null);
        }
    }

    private function failed(WebhookDelivery $d, bool $retry, string $error, ?int $http): void
    {
        $wait = $retry ? (self::BACKOFF[$d->attempt - 1] ?? null) : null;
        $d->forceFill($wait === null
            ? ['status' => WebhookDelivery::FAILED, 'http_status' => $http, 'error' => $error, 'next_attempt_at' => null]
            : ['status' => WebhookDelivery::PENDING, 'http_status' => $http, 'error' => $error, 'next_attempt_at' => now()->addMinutes($wait)])->save();
    }

    /** @param array<string, mixed> $data */
    private function payload(string $event, array $data): string
    {
        return (string) json_encode(['event' => $event, 'occurred_at' => now()->toIso8601String()] + $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
