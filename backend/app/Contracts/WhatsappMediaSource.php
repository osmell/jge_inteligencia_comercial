<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\WhatsappSetting;
use Illuminate\Http\Client\Response;

/** Puerto de entrada de archivos de WhatsApp: datos del archivo y su descarga. */
interface WhatsappMediaSource
{
    /** Tipo, tamaño, firma y dirección de descarga del archivo. */
    public function info(WhatsappSetting $setting, string $mediaId): Response;

    public function download(WhatsappSetting $setting, string $url): Response;
}
