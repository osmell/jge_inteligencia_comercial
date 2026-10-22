<?php

declare(strict_types=1);

namespace App\Services;

use App\Jobs\DownloadWhatsappMedia;
use App\Models\Customer;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Mensajes que llegan de los clientes por el webhook de Meta. Cada mensaje se guarda una sola vez (por su identificador de Meta),
 * en la conversación del número del cliente; los números desconocidos van a la bandeja «sin asignar». Los archivos se descargan
 * después, en cola. Lo que el sistema no entiende se guarda como «mensaje no compatible», nunca se descarta.
 */
class WhatsappInbound
{
    public function __construct(private readonly ConversationAlerts $alerts, private readonly ConversationAway $away) {}

    private const MEDIA = ['image' => 'image', 'video' => 'video', 'audio' => 'audio', 'voice' => 'audio', 'document' => 'document', 'sticker' => 'image'];

    /**
     * @param  array<string, mixed>  $payload  cuerpo del webhook
     * @return int mensajes nuevos guardados
     */
    public function process(array $payload): int
    {
        $s = WhatsappSetting::current();
        $saved = 0;
        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $value = (array) ($change['value'] ?? []);
                // Solo el número configurado: un aviso de otra cuenta se ignora.
                if (empty($value['messages']) || ($s->phone_number_id && ($value['metadata']['phone_number_id'] ?? null) !== $s->phone_number_id)) {
                    continue;
                }
                $names = [];
                foreach ((array) ($value['contacts'] ?? []) as $contact) {
                    $names[$this->digits($contact['wa_id'] ?? '')] = (string) ($contact['profile']['name'] ?? '');
                }
                foreach ((array) $value['messages'] as $message) {
                    $saved += $this->store((array) $message, $names) ? 1 : 0;
                }
            }
        }

        return $saved;
    }

    /** @param array<string, mixed> $m @param array<string, string> $names */
    private function store(array $m, array $names): bool
    {
        $waId = (string) ($m['id'] ?? '');
        $from = $this->digits($m['from'] ?? '');
        if ($waId === '' || $from === '' || WhatsappMessage::query()->where('wa_message_id', $waId)->exists()) {
            return false;                                       // repetido (Meta reintenta) o incompleto
        }
        [$type, $body, $mediaId, $filename] = $this->content($m);
        $at = isset($m['timestamp']) ? now()->setTimestamp((int) $m['timestamp']) : now();

        try {
            return DB::transaction(function () use ($m, $waId, $from, $names, $type, $body, $mediaId, $filename, $at) {
                $conversation = $this->conversation($from, $names[$from] ?? null);
                $replyTo = isset($m['context']['id']) ? WhatsappMessage::query()->where('wa_message_id', $m['context']['id'])->value('id') : null;
                if ($type === 'reaction') {
                    $replyTo = isset($m['reaction']['message_id']) ? WhatsappMessage::query()->where('wa_message_id', $m['reaction']['message_id'])->value('id') : null;
                }
                $message = WhatsappMessage::create([
                    'conversation_id' => $conversation->id, 'customer_id' => $conversation->customer_id, 'to_number' => null, 'body' => $body, 'kind' => 'inbound', 'direction' => 'in', 'message_type' => $type,
                    'status' => WhatsappMessage::RECEIVED, 'wa_message_id' => $waId, 'reply_to_id' => $replyTo, 'created_at' => $at,
                ]);
                $attachment = $mediaId === null ? null : WhatsappAttachment::create([
                    'message_id' => $message->id, 'kind' => self::MEDIA[$m['type'] ?? ''] ?? 'document', 'original_name' => $filename, 'media_id' => $mediaId, 'status' => WhatsappAttachment::PENDING, 'created_at' => now(),
                ]);
                $conversation->forceFill([
                    'last_message_at' => $at, 'last_inbound_at' => $at, 'status' => WhatsappConversation::OPEN,
                    'last_message_preview' => mb_substr($body !== '' ? $body : $this->label($type), 0, 200),
                ])->save();
                if ($type !== 'reaction') {
                    DB::afterCommit(function () use ($message, $conversation) {
                        $this->alerts->inbound($message);
                        $this->away->handle($conversation);
                    });
                }
                if ($attachment !== null) {
                    DB::afterCommit(fn () => DownloadWhatsappMedia::dispatch($attachment->id));
                }

                return true;
            });
        } catch (QueryException) {
            return false;                                       // otro proceso lo guardó al mismo tiempo (identificador único)
        }
    }

    /** @param array<string, mixed> $m @return array{0: string, 1: string, 2: string|null, 3: string|null} tipo, texto, id de archivo, nombre de archivo */
    private function content(array $m): array
    {
        $type = (string) ($m['type'] ?? '');
        $unsupported = ['unsupported', '[Mensaje no compatible con el sistema; revísalo en la aplicación de WhatsApp.]', null, null];

        return match (true) {
            $type === 'text' => ['text', (string) ($m['text']['body'] ?? ''), null, null],
            isset(self::MEDIA[$type]) && ! empty($m[$type]['id']) => [$type === 'document' ? 'document' : (self::MEDIA[$type]), (string) ($m[$type]['caption'] ?? ''), (string) $m[$type]['id'], isset($m[$type]['filename']) ? mb_substr((string) $m[$type]['filename'], 0, 200) : null],
            $type === 'location' => ['location', trim(implode(' · ', array_filter([$m['location']['name'] ?? null, $m['location']['address'] ?? null, ($m['location']['latitude'] ?? '').','.($m['location']['longitude'] ?? '')]))), null, null],
            $type === 'reaction' => ['reaction', (string) ($m['reaction']['emoji'] ?? ''), null, null],
            $type === 'button' => ['text', (string) ($m['button']['text'] ?? ''), null, null],
            $type === 'interactive' => ['text', (string) ($m['interactive']['button_reply']['title'] ?? $m['interactive']['list_reply']['title'] ?? ''), null, null],
            $type === 'contacts' => ['unsupported', '[El cliente compartió un contacto]', null, null],
            default => $unsupported,
        };
    }

    private function label(string $type): string
    {
        return ['image' => '📷 Imagen', 'video' => '🎬 Video', 'audio' => '🎤 Audio', 'document' => '📄 Documento', 'location' => '📍 Ubicación', 'reaction' => 'Reacción', 'unsupported' => 'Mensaje no compatible'][$type] ?? 'Mensaje';
    }

    /** Conversación del número: la que ya existe (la más reciente) o una nueva en la compañía del cliente (o en la bandeja sin asignar). */
    private function conversation(string $number, ?string $profileName): WhatsappConversation
    {
        $existing = WhatsappConversation::query()->where('wa_number', $number)->orderByDesc('last_message_at')->orderByDesc('id')->first();
        $customer = $existing?->customer_id === null ? $this->customerFor($number) : null;
        if ($existing === null) {
            $existing = WhatsappConversation::query()->create([
                'company_id' => $customer !== null ? $customer->company_id : $this->fallbackCompany(), 'customer_id' => $customer?->id, 'wa_number' => $number, 'created_at' => now(),
            ]);
        } elseif ($customer !== null && $customer->company_id === $existing->company_id) {
            $existing->forceFill(['customer_id' => $customer->id])->save();      // un número desconocido que ya es de un cliente
        }
        if ($profileName !== null && $profileName !== '' && $existing->profile_name !== $profileName) {
            $existing->forceFill(['profile_name' => mb_substr($profileName, 0, 255)])->save();
        }

        return $existing;
    }

    /** Cliente con ese número de WhatsApp; si lo comparten varias compañías, el de la compra más reciente. */
    private function customerFor(string $number): ?Customer
    {
        $matches = Customer::withoutGlobalScopes()->whereNotNull('whatsapp')->get()->filter(fn (Customer $c) => $this->digits($c->whatsapp) === $number);
        if ($matches->count() <= 1) {
            return $matches->first();
        }
        $last = DB::table('sales_documents')->selectRaw('company_id, customer_code, max(issued_on) as last')->groupBy('company_id', 'customer_code')->get()
            ->mapWithKeys(fn ($r) => [$r->company_id.'|'.$r->customer_code => (string) $r->last]);

        return $matches->sortByDesc(fn (Customer $c) => $last[$c->company_id.'|'.$c->code] ?? '')->first();
    }

    private function fallbackCompany(): int
    {
        return (int) (WhatsappSetting::current()->unassigned_company_id ?? DB::table('companies')->where('is_active', true)->orderBy('id')->value('id'));
    }

    private function digits(?string $raw): string
    {
        return preg_replace('/\D+/', '', (string) $raw) ?? '';
    }
}
