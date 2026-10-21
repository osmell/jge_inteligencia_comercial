<?php

namespace App\Jobs;

use App\Models\WhatsappAttachment;
use App\Services\WhatsappMedia;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Descarga un archivo entrante de WhatsApp (reintenta ante fallos de red; el enlace de Meta caduca en minutos). */
class DownloadWhatsappMedia implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public int $attachmentId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(WhatsappMedia $media): void
    {
        $attachment = WhatsappAttachment::query()->find($this->attachmentId);
        if ($attachment !== null) {
            $media->download($attachment);
        }
    }

    public function failed(Throwable $e): void
    {
        $attachment = WhatsappAttachment::query()->find($this->attachmentId);
        if ($attachment !== null) {
            app(WhatsappMedia::class)->fail($attachment, 'No se pudo descargar tras varios intentos.');
        }
    }
}
