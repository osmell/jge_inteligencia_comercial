<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappConsent;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use App\Models\WhatsappTemplate;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * WhatsApp Business Platform (Cloud API). Reglas: las credenciales viven cifradas en la base de datos y no se devuelven; solo se
 * envían plantillas aprobadas; nunca a un cliente marcado «no contactar», sin consentimiento vigente o sin número; cada intento
 * (enviado o no) queda en el registro con su motivo; el mismo mensaje al mismo cliente el mismo día no se repite.
 */
class WhatsappService
{
    private const RANK = ['sent' => 1, 'delivered' => 2, 'read' => 3];

    public function __construct(private readonly AuditLogger $audit) {}

    /** @return array<string, mixed> configuración sin credenciales */
    public function describe(): array
    {
        $s = WhatsappSetting::current();

        return [
            'enabled' => $s->enabled, 'phone_number_id' => $s->phone_number_id, 'business_account_id' => $s->business_account_id, 'api_version' => $s->api_version,
            'unassigned_company_id' => $s->unassigned_company_id, 'has_access_token' => ! empty($s->access_token), 'has_app_secret' => ! empty($s->app_secret), 'has_verify_token' => ! empty($s->verify_token),
            'away_enabled' => $s->away_enabled, 'away_message' => $s->away_message, 'away_start_hour' => $s->away_start_hour, 'away_end_hour' => $s->away_end_hour, 'away_days' => array_values(array_map('intval', array_filter(explode(',', (string) $s->away_days), fn (string $d) => $d !== ''))),
            'ready' => $s->ready(), 'updated_at' => $s->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $data  credenciales vacías o ausentes = conservar */
    public function save(User $user, array $data): void
    {
        $current = WhatsappSetting::current();
        $before = $this->describe();
        if ($data['enabled'] && (empty($data['phone_number_id']) || (empty($data['access_token']) && empty($current->access_token)))) {
            throw ValidationException::withMessages(['phone_number_id' => 'Para activar WhatsApp indica el identificador del número y el token de acceso.']);
        }
        $keep = fn (string $key) => ($data[$key] ?? '') !== '' ? (string) $data[$key] : $current->{$key};
        if (($data['away_enabled'] ?? false) && trim((string) ($data['away_message'] ?? $current->away_message)) === '') {
            throw ValidationException::withMessages(['away_message' => 'Escribe el mensaje de ausencia para activarlo.']);
        }
        $setting = $current->exists ? $current : new WhatsappSetting(['id' => 1]);
        $setting->forceFill([
            'id' => 1, 'enabled' => (bool) $data['enabled'], 'phone_number_id' => $data['phone_number_id'] ?? null, 'business_account_id' => $data['business_account_id'] ?? null, 'api_version' => $data['api_version'], 'unassigned_company_id' => $data['unassigned_company_id'] ?? null,
            'away_enabled' => (bool) ($data['away_enabled'] ?? $current->away_enabled), 'away_message' => array_key_exists('away_message', $data) ? $data['away_message'] : $current->away_message,
            'away_start_hour' => (int) ($data['away_start_hour'] ?? $current->away_start_hour ?? 8), 'away_end_hour' => (int) ($data['away_end_hour'] ?? $current->away_end_hour ?? 18),
            'away_days' => isset($data['away_days']) ? implode(',', array_unique(array_map('intval', $data['away_days']))) : ($current->away_days ?? '1,2,3,4,5,6'),
            'access_token' => $keep('access_token'), 'app_secret' => $keep('app_secret'), 'verify_token' => $keep('verify_token'), 'updated_by' => $user->id, 'updated_at' => now(),
        ])->save();

        $after = $this->describe();
        $changed = array_keys(array_filter($after, fn ($v, $k) => $k !== 'updated_at' && $before[$k] !== $v, ARRAY_FILTER_USE_BOTH));
        $secrets = array_values(array_filter(['access_token', 'app_secret', 'verify_token'], fn (string $k) => ($data[$k] ?? '') !== ''));
        if ($changed !== [] || $secrets !== []) {
            $this->audit->record('whatsapp.settings_updated', $user, old: array_intersect_key($before, array_flip($changed)), new: array_intersect_key($after, array_flip($changed)) + array_fill_keys($secrets, 'cambiado'));
        }
    }

    /** Consulta el número en Meta con las credenciales guardadas. @return array{ok: bool, message: string} */
    public function testConnection(User $user): array
    {
        $s = WhatsappSetting::current();
        if (empty($s->phone_number_id) || empty($s->access_token)) {
            return ['ok' => false, 'message' => 'Faltan el identificador del número o el token de acceso.'];
        }
        try {
            $r = Http::withToken((string) $s->access_token)->timeout(15)->get($this->url($s, (string) $s->phone_number_id), ['fields' => 'display_phone_number,verified_name']);
            $result = $r->successful()
                ? ['ok' => true, 'message' => 'Conexión correcta con '.($r->json('verified_name') ?? 'la cuenta').' ('.($r->json('display_phone_number') ?? 'número sin dato').').']
                : ['ok' => false, 'message' => 'Meta respondió: '.mb_substr((string) ($r->json('error.message') ?? $r->status()), 0, 300)];
        } catch (Throwable $e) {
            $result = ['ok' => false, 'message' => 'No se pudo conectar: '.mb_substr($e->getMessage(), 0, 300)];
        }
        $this->audit->record('whatsapp.connection_tested', $user, new: ['ok' => $result['ok']]);

        return $result;
    }

    /** Consentimiento vigente del cliente: granted | revoked | null (nunca registrado). */
    public function consentOf(Customer $customer): ?string
    {
        return WhatsappConsent::query()->where('customer_id', $customer->id)->orderByDesc('id')->value('status');
    }

    public function recordConsent(User $user, Customer $customer, string $status, string $source): WhatsappConsent
    {
        $consent = WhatsappConsent::create(['customer_id' => $customer->id, 'status' => $status, 'source' => $source, 'recorded_by' => $user->id, 'created_at' => now()]);
        $this->audit->record('whatsapp.consent_'.$status, $user, auditable: $customer, new: ['source' => $source], companyId: $customer->company_id);

        return $consent;
    }

    /** @return list<string> motivos por los que NO se le puede escribir a este cliente */
    public function blockers(Customer $customer): array
    {
        $out = [];
        if ($customer->do_not_contact) {
            $out[] = 'El cliente está marcado «no contactar».';
        }
        if (empty($this->number($customer->whatsapp))) {
            $out[] = 'El cliente no tiene número de WhatsApp.';
        }
        if ($this->consentOf($customer) !== 'granted') {
            $out[] = 'No hay consentimiento vigente del cliente para WhatsApp.';
        }

        return $out;
    }

    /**
     * Envía una plantilla aprobada a un cliente. Lo que no se puede enviar queda registrado como «no enviado» con su motivo.
     *
     * @param  array<int, mixed>  $params
     */
    public function send(User $user, Customer $customer, WhatsappTemplate $template, array $params): WhatsappMessage
    {
        $params = array_values(array_map('strval', $params));
        $body = $template->render($params);
        $key = substr(hash('sha256', $template->id.'|'.implode('|', $params).'|'.now()->toDateString()), 0, 40);
        $existing = WhatsappMessage::query()->where(['customer_id' => $customer->id, 'dedupe_key' => $key])->first();
        if ($existing !== null && $existing->status !== WhatsappMessage::NOT_SENT) {
            return $existing;
        }
        $reasons = $this->blockers($customer);
        $s = WhatsappSetting::current();
        if ($template->status !== 'approved') {
            $reasons[] = 'La plantilla no está aprobada.';
        }
        if (count($params) < $template->variableCount()) {
            $reasons[] = 'Faltan valores para las variables de la plantilla.';
        }
        if (! $s->ready()) {
            $reasons[] = 'WhatsApp no está activado o configurado.';
        }

        $log = $existing ?? new WhatsappMessage;
        $conversation = $this->conversationFor($customer);
        $log->forceFill([
            'customer_id' => $customer->id, 'template_id' => $template->id, 'user_id' => $user->id, 'to_number' => $this->number($customer->whatsapp), 'body' => $body, 'kind' => 'template', 'dedupe_key' => $key,
            'conversation_id' => $conversation?->id, 'direction' => 'out', 'message_type' => 'template', 'created_at' => $log->created_at ?? now(),
        ]);
        if ($reasons !== []) {
            $log->forceFill(['status' => WhatsappMessage::NOT_SENT, 'error' => implode(' ', $reasons)])->save();
        } else {
            $this->deliver($log, $s, $template, $params);
        }
        $this->audit->record('whatsapp.message_'.$log->status, $user, auditable: $customer, new: ['template' => $template->name], companyId: $customer->company_id);
        $conversation?->forceFill(['last_message_at' => $log->created_at, 'last_message_preview' => mb_substr($body, 0, 200)])->save();

        return $log;
    }

    /** Conversación del cliente con la empresa (se crea al primer mensaje); null si el cliente no tiene número de WhatsApp. */
    public function conversationFor(Customer $customer): ?WhatsappConversation
    {
        $number = $this->number($customer->whatsapp);
        if ($number === '') {
            return null;
        }
        $conversation = WhatsappConversation::query()->firstOrCreate(['company_id' => $customer->company_id, 'wa_number' => $number], ['customer_id' => $customer->id, 'created_at' => now()]);
        if ($conversation->customer_id === null) {
            $conversation->forceFill(['customer_id' => $customer->id])->save();      // un número que era desconocido y ya es de un cliente
        }

        return $conversation;
    }

    /** @param list<string> $params */
    private function deliver(WhatsappMessage $log, WhatsappSetting $s, WhatsappTemplate $template, array $params): void
    {
        try {
            $payload = [
                'messaging_product' => 'whatsapp', 'to' => $log->to_number, 'type' => 'template',
                'template' => ['name' => $template->name, 'language' => ['code' => $template->language], 'components' => $params === [] ? [] : [['type' => 'body', 'parameters' => array_map(fn (string $p) => ['type' => 'text', 'text' => $p], $params)]]],
            ];
            $r = Http::withToken((string) $s->access_token)->timeout(20)->post($this->url($s, $s->phone_number_id.'/messages'), $payload);
            if ($r->successful()) {
                $log->forceFill(['status' => WhatsappMessage::SENT, 'wa_message_id' => $r->json('messages.0.id'), 'sent_at' => now(), 'error' => null])->save();
            } else {
                $log->forceFill(['status' => WhatsappMessage::FAILED, 'error' => 'Meta respondió: '.mb_substr((string) ($r->json('error.message') ?? $r->status()), 0, 800)])->save();
            }
        } catch (Throwable $e) {
            $log->forceFill(['status' => WhatsappMessage::FAILED, 'error' => mb_substr($e->getMessage(), 0, 900)])->save();
        }
    }

    /** Valida la firma de Meta (X-Hub-Signature-256) con el secreto de la aplicación. */
    public function validSignature(string $payload, ?string $header): bool
    {
        $secret = WhatsappSetting::current()->app_secret;

        return ! empty($secret) && $header !== null && hash_equals('sha256='.hash_hmac('sha256', $payload, (string) $secret), $header);
    }

    public function validVerifyToken(?string $token): bool
    {
        $expected = WhatsappSetting::current()->verify_token;

        return ! empty($expected) && $token !== null && hash_equals((string) $expected, $token);
    }

    /**
     * Aplica los estados que reporta Meta (enviado, entregado, leído, fallido). Un estado nunca retrocede.
     *
     * @param  array<string, mixed>  $payload
     */
    public function applyStatuses(array $payload): int
    {
        $applied = 0;
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                foreach ((array) ($change['value']['statuses'] ?? []) as $st) {
                    $log = WhatsappMessage::query()->where('wa_message_id', $st['id'] ?? '')->first();
                    if ($log === null) {
                        continue;
                    }
                    $new = $st['status'] ?? '';
                    $at = isset($st['timestamp']) ? now()->setTimestamp((int) $st['timestamp']) : now();
                    if ($new === 'failed') {
                        $log->forceFill(['status' => WhatsappMessage::FAILED, 'error' => mb_substr((string) ($st['errors'][0]['title'] ?? 'Meta reportó un fallo.'), 0, 900)])->save();
                    } elseif (isset(self::RANK[$new]) && self::RANK[$new] > (self::RANK[$log->status] ?? 0)) {
                        $log->forceFill(['status' => $new] + ($new === 'delivered' ? ['delivered_at' => $at] : ['read_at' => $at, 'delivered_at' => $log->delivered_at ?? $at]))->save();
                    } else {
                        continue;
                    }
                    $applied++;
                }
            }
        }

        return $applied;
    }

    private function url(WhatsappSetting $s, string $path): string
    {
        return "https://graph.facebook.com/{$s->api_version}/{$path}";
    }

    /** Solo dígitos, con código de país. */
    public function number(?string $raw): string
    {
        return preg_replace('/\D+/', '', (string) $raw) ?? '';
    }
}
