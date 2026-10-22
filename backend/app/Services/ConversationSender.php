<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WhatsappMessaging;
use App\Jobs\SendWhatsappMessage;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Mensajes libres (texto y archivos) de una conversación. Reglas:
 *  - Solo con la ventana de 24 horas abierta (fuera de ella, únicamente plantillas aprobadas, por WhatsappService::send).
 *  - Nunca a un cliente «no contactar». Como quien escribe responde a un cliente que escribió primero, aquí no se exige el
 *    consentimiento previo que sí pide el envío de plantillas.
 *  - El archivo se valida por su contenido real y por los tamaños de WhatsApp, y se guarda en el disco privado.
 *  - El mensaje se guarda antes de enviarse («en cola»); una clave del cliente evita duplicados y los reintentos no repiten el envío.
 */
class ConversationSender
{
    /** Tipos que WhatsApp admite al enviar (los entrantes admiten además stickers webp). */
    private const OFFICE = ['doc' => 'application/msword', 'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'xls' => 'application/vnd.ms-excel', 'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'ppt' => 'application/vnd.ms-powerpoint', 'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation'];

    private const ALIASES = ['application/ogg' => 'audio/ogg', 'audio/x-m4a' => 'audio/mp4', 'audio/x-aac' => 'audio/aac', 'audio/mp3' => 'audio/mpeg', 'audio/x-hx-aac-adts' => 'audio/aac', 'audio/amr-nb' => 'audio/amr'];

    private const CONTAINERS = ['application/zip', 'application/octet-stream', 'application/x-ole-storage', 'application/CDFV2', 'application/vnd.ms-office', 'application/x-tika-msoffice', 'application/x-tika-ooxml'];

    public function __construct(private readonly AuditLogger $audit, private readonly WhatsappMessaging $whatsapp, private readonly ConversationLog $log) {}

    /** Guarda el mensaje (y su archivo) y lo deja en cola; devuelve el ya existente si la clave se repite. */
    public function send(User $user, WhatsappConversation $c, ?string $body, ?UploadedFile $file, ?int $replyToId, ?string $clientKey): WhatsappMessage
    {
        if ($clientKey !== null) {
            $existing = WhatsappMessage::query()->where(['conversation_id' => $c->id, 'client_key' => $clientKey])->first();
            if ($existing !== null) {
                return $existing;
            }
        }
        $body = $body === null ? null : trim($body);
        if (($body === null || $body === '') && $file === null) {
            throw ValidationException::withMessages(['body' => 'Escribe un mensaje o adjunta un archivo.']);
        }
        $this->assertCanWrite($c);
        $reply = $replyToId === null ? null : WhatsappMessage::query()->where(['id' => $replyToId, 'conversation_id' => $c->id])->first();
        if ($replyToId !== null && $reply === null) {
            throw ValidationException::withMessages(['reply_to_id' => 'El mensaje citado no pertenece a esta conversación.']);
        }
        $checked = ['kind' => 'text', 'mime' => '', 'extension' => ''];
        if ($file !== null) {
            $checked = $this->inspect($file, (string) $body);
        }

        $message = DB::transaction(function () use ($user, $c, $body, $file, $checked, $reply, $clientKey) {
            $message = new WhatsappMessage;
            $message->forceFill([
                'customer_id' => $c->customer_id, 'user_id' => $user->id, 'conversation_id' => $c->id, 'direction' => 'out', 'to_number' => $c->wa_number,
                'message_type' => $checked['kind'], 'body' => (string) $body, 'kind' => 'conversation', 'status' => WhatsappMessage::QUEUED,
                'reply_to_id' => $reply?->id, 'client_key' => $clientKey, 'created_at' => now(),
            ])->save();
            if ($file !== null) {
                $bytes = (string) file_get_contents($file->getRealPath());
                $path = sprintf('%d/%s/%s.%s', $c->company_id, now()->format('Y/m'), Str::uuid(), $checked['extension']);
                Storage::disk('conversations')->put($path, $bytes);
                WhatsappAttachment::create([
                    'message_id' => $message->id, 'kind' => $checked['kind'], 'path' => $path, 'original_name' => $this->cleanName($file->getClientOriginalName(), $checked['extension']),
                    'mime' => $checked['mime'], 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'status' => WhatsappAttachment::STORED, 'created_at' => now(),
                ]);
            }
            $preview = $body !== null && $body !== '' ? $body : '['.$this->label($checked['kind']).']';
            $c->forceFill(['last_message_at' => $message->created_at, 'last_message_preview' => mb_substr($preview, 0, 200)])->save();

            return $message;
        });
        $this->audit->record('conversations.message_queued', $user, auditable: $c, new: ['type' => $message->message_type], companyId: $c->company_id);
        SendWhatsappMessage::dispatch($message->id)->afterCommit();

        return $message;
    }

    /** Mensaje automático del sistema (por ejemplo, el de ausencia): sin persona que lo escriba, solo con la ventana abierta. */
    public function automatic(WhatsappConversation $c, string $body): ?WhatsappMessage
    {
        $customer = $c->customer_id === null ? null : Customer::withoutGlobalScopes()->find($c->customer_id);
        if (! WhatsappSetting::current()->ready() || ! $c->windowOpen() || $customer?->do_not_contact === true) {
            return null;
        }
        $message = new WhatsappMessage;
        $message->forceFill([
            'customer_id' => $c->customer_id, 'user_id' => null, 'conversation_id' => $c->id, 'direction' => 'out', 'to_number' => $c->wa_number, 'message_type' => 'text',
            'body' => $body, 'kind' => 'away', 'status' => WhatsappMessage::QUEUED, 'created_at' => now(),
        ])->save();
        $c->forceFill(['last_message_at' => $message->created_at, 'last_message_preview' => mb_substr($body, 0, 200)])->save();
        SendWhatsappMessage::dispatch($message->id)->afterCommit();

        return $message;
    }

    /** Vuelve a poner en cola un mensaje fallido (solo mensajes libres, y con la ventana todavía abierta). */
    public function retry(User $user, WhatsappMessage $message): WhatsappMessage
    {
        $c = WhatsappConversation::query()->findOrFail($message->conversation_id);
        if ($message->direction !== 'out' || $message->kind !== 'conversation' || $message->status !== WhatsappMessage::FAILED) {
            throw ValidationException::withMessages(['message' => 'Solo se pueden reenviar los mensajes que fallaron.']);
        }
        $this->assertCanWrite($c);
        $message->forceFill(['status' => WhatsappMessage::QUEUED, 'error' => null])->save();
        $this->audit->record('conversations.message_retried', $user, auditable: $c, new: ['message_id' => $message->id], companyId: $c->company_id);
        SendWhatsappMessage::dispatch($message->id)->afterCommit();

        return $message;
    }

    /** Entrega el mensaje a Meta. Lanza ante fallos que conviene reintentar; un mensaje que ya salió nunca se vuelve a enviar. */
    public function deliver(WhatsappMessage $message): void
    {
        if ($message->status !== WhatsappMessage::QUEUED || $message->wa_message_id !== null) {
            return;
        }
        $s = WhatsappSetting::current();
        if (! $s->ready()) {
            $this->giveUp($message, 'WhatsApp no está activado o configurado.');

            return;
        }
        $file = WhatsappAttachment::query()->where('message_id', $message->id)->first();
        $mediaId = $file?->media_id;
        if ($file !== null && $mediaId === null) {
            $mediaId = $this->upload($s, $message, $file);
            if ($mediaId === null) {
                return;
            }
        }

        $type = $file === null ? 'text' : $file->kind;
        $content = $file === null
            ? ['body' => $message->body, 'preview_url' => false]
            : ['id' => $mediaId] + ($type === 'document' ? ['filename' => $file->original_name] : []) + ($message->body !== '' && $type !== 'audio' ? ['caption' => $message->body] : []);
        $payload = ['messaging_product' => 'whatsapp', 'to' => $message->to_number, 'type' => $type, $type => $content];
        $quoted = $message->reply_to_id === null ? null : WhatsappMessage::query()->whereKey($message->reply_to_id)->value('wa_message_id');
        if ($quoted !== null) {
            $payload['context'] = ['message_id' => $quoted];
        }
        $r = $this->call(fn () => $this->whatsapp->send($s, $payload));
        if ($r->successful() && $r->json('messages.0.id')) {
            $message->forceFill(['status' => WhatsappMessage::SENT, 'wa_message_id' => $r->json('messages.0.id'), 'sent_at' => now(), 'error' => null])->save();
            $this->log->contact($message);
        } else {
            $this->giveUp($message, 'Meta respondió: '.mb_substr((string) ($r->json('error.message') ?? $r->status()), 0, 800));
        }
    }

    /** Marca el mensaje como fallido (se puede reenviar a mano). */
    public function giveUp(WhatsappMessage $message, string $reason): void
    {
        if ($message->status === WhatsappMessage::QUEUED) {
            $message->forceFill(['status' => WhatsappMessage::FAILED, 'error' => mb_substr($reason, 0, 900)])->save();
        }
    }

    /** Sube el archivo a Meta y devuelve su identificador; null si el mensaje quedó fallido. */
    private function upload(WhatsappSetting $s, WhatsappMessage $message, WhatsappAttachment $file): ?string
    {
        $bytes = Storage::disk('conversations')->get((string) $file->path);
        if ($bytes === null) {
            $this->giveUp($message, 'El archivo ya no está en el servidor.');

            return null;
        }
        $r = $this->call(fn () => $this->whatsapp->upload($s, $bytes, basename((string) $file->path), (string) $file->mime));
        if (! $r->successful() || ! $r->json('id')) {
            $this->giveUp($message, 'Meta no aceptó el archivo: '.mb_substr((string) ($r->json('error.message') ?? $r->status()), 0, 800));

            return null;
        }
        // El adjunto es inmutable para el modelo; el identificador de Meta se anota directamente para no repetir la subida al reintentar.
        DB::table('whatsapp_attachments')->where('id', $file->id)->update(['media_id' => $r->json('id')]);

        return (string) $r->json('id');
    }

    /** Ejecuta la llamada a Meta; los fallos de red o del servidor de Meta se relanzan para que la cola reintente. */
    private function call(callable $request): Response
    {
        try {
            $r = $request();
        } catch (ConnectionException $e) {
            throw new RuntimeException('Sin conexión con Meta; se reintentará.', 0, $e);
        }
        if ($r->serverError() || $r->status() === 429) {
            throw new RuntimeException('Meta no respondió ('.$r->status().'); se reintentará.');
        }

        return $r;
    }

    private function assertCanWrite(WhatsappConversation $c): void
    {
        $customer = $c->customer_id === null ? null : Customer::withoutGlobalScopes()->find($c->customer_id);
        if ($customer === null) {
            throw ValidationException::withMessages(['conversation' => 'La conversación no tiene un cliente asignado.']);
        }
        if ($customer->do_not_contact) {
            throw ValidationException::withMessages(['conversation' => 'El cliente está marcado «no contactar».']);
        }
        if (! WhatsappSetting::current()->ready()) {
            throw ValidationException::withMessages(['conversation' => 'WhatsApp no está activado o configurado.']);
        }
        if (! $c->windowOpen()) {
            throw ValidationException::withMessages(['conversation' => 'Pasaron más de 24 horas desde el último mensaje del cliente: solo se puede enviar una plantilla aprobada.']);
        }
    }

    /**
     * Valida el archivo por su contenido real.
     *
     * @return array{kind: string, mime: string, extension: string}
     */
    private function inspect(UploadedFile $file, string $body): array
    {
        $path = (string) $file->getRealPath();
        $real = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($path);
        $real = self::ALIASES[$real] ?? $real;
        $ext = strtolower($file->getClientOriginalExtension());
        $mime = $real;
        if (isset(self::OFFICE[$ext]) && in_array($real, self::CONTAINERS, true)) {
            $mime = self::OFFICE[$ext];
        } elseif ($ext === 'm4a' && $real === 'video/mp4') {
            $mime = 'audio/mp4';
        }
        if (! isset(WhatsappMedia::TYPES[$mime]) || $mime === 'image/webp') {
            throw ValidationException::withMessages(['file' => 'Este tipo de archivo no se puede enviar por WhatsApp (imágenes JPG/PNG, video MP4, audio, PDF, Word, Excel, PowerPoint o texto).']);
        }
        if ($mime === 'text/plain' && $ext !== 'txt') {
            throw ValidationException::withMessages(['file' => 'Solo se envían archivos de texto con extensión .txt.']);
        }
        [$kind, $extension] = WhatsappMedia::TYPES[$mime];
        if ((int) $file->getSize() > WhatsappMedia::MAX_BYTES[$kind]) {
            throw ValidationException::withMessages(['file' => 'El archivo supera el tamaño que WhatsApp permite ('.(WhatsappMedia::MAX_BYTES[$kind] / 1048576).' MB para '.$this->label($kind).').']);
        }
        if ($kind === 'audio' && $body !== '') {
            throw ValidationException::withMessages(['body' => 'Un audio no lleva texto: envíalo por separado.']);
        }

        return ['kind' => $kind, 'mime' => $mime, 'extension' => $extension];
    }

    private function cleanName(string $name, string $extension): string
    {
        $base = pathinfo(basename($name), PATHINFO_FILENAME);
        $base = trim((string) preg_replace('/[^\p{L}\p{N} ._()-]+/u', '', $base));

        return mb_substr($base === '' ? 'archivo' : $base, 0, 100).'.'.$extension;
    }

    private function label(string $kind): string
    {
        return ['image' => 'imagen', 'video' => 'video', 'audio' => 'audio', 'document' => 'documento', 'text' => 'mensaje'][$kind] ?? $kind;
    }
}
