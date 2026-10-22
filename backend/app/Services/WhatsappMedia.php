<?php

declare(strict_types=1);

namespace App\Services;

use App\Contracts\WhatsappMediaSource;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Models\WhatsappSetting;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Descarga a la carpeta privada los archivos que manda el cliente. Meta da un enlace que caduca en minutos, así que se hace en cola.
 * Solo se aceptan los tipos y tamaños que WhatsApp permite; lo demás queda como «fallido» con su motivo (el mensaje se conserva).
 */
class WhatsappMedia
{
    public function __construct(private readonly WhatsappMediaSource $source) {}

    /** Tipo declarado => [clase de archivo, extensión]. */
    public const TYPES = [
        'image/jpeg' => ['image', 'jpg'], 'image/png' => ['image', 'png'], 'image/webp' => ['image', 'webp'],
        'video/mp4' => ['video', 'mp4'], 'video/3gpp' => ['video', '3gp'],
        'audio/aac' => ['audio', 'aac'], 'audio/mp4' => ['audio', 'm4a'], 'audio/mpeg' => ['audio', 'mp3'], 'audio/amr' => ['audio', 'amr'], 'audio/ogg' => ['audio', 'ogg'],
        'application/pdf' => ['document', 'pdf'], 'text/plain' => ['document', 'txt'],
        'application/msword' => ['document', 'doc'], 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => ['document', 'docx'],
        'application/vnd.ms-excel' => ['document', 'xls'], 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => ['document', 'xlsx'],
        'application/vnd.ms-powerpoint' => ['document', 'ppt'], 'application/vnd.openxmlformats-officedocument.presentationml.presentation' => ['document', 'pptx'],
    ];

    /** Tamaño máximo por clase de archivo (los límites de WhatsApp). */
    public const MAX_BYTES = ['image' => 5 * 1024 * 1024, 'video' => 16 * 1024 * 1024, 'audio' => 16 * 1024 * 1024, 'document' => 100 * 1024 * 1024];

    /** Descarga y guarda el archivo; deja el adjunto «guardado» o «fallido». Lanza si conviene reintentar (fallo de red). */
    public function download(WhatsappAttachment $attachment): void
    {
        if ($attachment->status !== WhatsappAttachment::PENDING) {
            return;
        }
        $s = WhatsappSetting::current();
        if (empty($s->access_token) || empty($attachment->media_id)) {
            $this->fail($attachment, 'Faltan las credenciales de WhatsApp o el identificador del archivo.');

            return;
        }

        $meta = $this->source->info($s, (string) $attachment->media_id);
        if ($meta->serverError() || $meta->status() === 429) {
            throw new RuntimeException('Meta no respondió ('.$meta->status().'); se reintentará.');
        }
        $mime = strtolower(trim(explode(';', (string) $meta->json('mime_type'))[0]));
        if (! $meta->successful() || empty($meta->json('url'))) {
            $this->fail($attachment, 'Meta no entregó el archivo: '.mb_substr((string) ($meta->json('error.message') ?? $meta->status()), 0, 200));

            return;
        }
        if (! isset(self::TYPES[$mime])) {
            $this->fail($attachment, "Tipo de archivo no permitido ($mime).");

            return;
        }
        [$kind, $extension] = self::TYPES[$mime];
        if ((int) $meta->json('file_size') > self::MAX_BYTES[$kind]) {
            $this->fail($attachment, 'El archivo supera el tamaño permitido.');

            return;
        }

        $file = $this->source->download($s, (string) $meta->json('url'));
        if ($file->serverError() || $file->status() === 429) {
            throw new RuntimeException('La descarga falló ('.$file->status().'); se reintentará.');
        }
        $bytes = $file->body();
        if (! $file->successful()) {
            $this->fail($attachment, 'No se pudo descargar el archivo ('.$file->status().').');
        } elseif (strlen($bytes) > self::MAX_BYTES[$kind]) {
            $this->fail($attachment, 'El archivo supera el tamaño permitido.');
        } elseif ($meta->json('sha256') && ! hash_equals((string) $meta->json('sha256'), hash('sha256', $bytes))) {
            $this->fail($attachment, 'El archivo descargado no coincide con su firma.');
        } elseif (! $this->magicMatches($kind, $mime, $bytes)) {
            $this->fail($attachment, 'El contenido no corresponde al tipo declarado.');
        } else {
            $companyId = WhatsappConversation::query()->whereKey(WhatsappMessage::query()->whereKey($attachment->message_id)->value('conversation_id'))->value('company_id') ?? 0;
            $path = sprintf('%d/%s/%s.%s', $companyId, now()->format('Y/m'), Str::uuid(), $extension);
            Storage::disk('conversations')->put($path, $bytes);
            $attachment->forceFill(['status' => WhatsappAttachment::STORED, 'kind' => $kind, 'path' => $path, 'mime' => $mime, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'error' => null])->save();
        }
    }

    /** Marca el adjunto como fallido (también cuando se agotan los reintentos). */
    public function fail(WhatsappAttachment $attachment, string $reason): void
    {
        if ($attachment->status === WhatsappAttachment::PENDING) {
            $attachment->forceFill(['status' => WhatsappAttachment::FAILED, 'error' => mb_substr($reason, 0, 500)])->save();
        }
    }

    /** Las imágenes y los PDF se comprueban por su contenido real, no solo por lo que declara el remitente. */
    private function magicMatches(string $kind, string $mime, string $bytes): bool
    {
        if (! in_array($kind, ['image'], true) && $mime !== 'application/pdf') {
            return true;
        }
        $real = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);

        return $mime === 'application/pdf' ? $real === 'application/pdf' : $real === $mime;
    }
}
