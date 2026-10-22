<?php

declare(strict_types=1);

namespace App\Contracts;

use App\Models\WhatsappSetting;
use Illuminate\Http\Client\Response;

/**
 * Puerto de salida hacia WhatsApp: consultar la cuenta, enviar mensajes y subir archivos.
 * Los errores de red se lanzan como `ConnectionException`; una respuesta con error HTTP se devuelve para que quien llama decida.
 */
interface WhatsappMessaging
{
    /** Datos del número (nombre verificado y número visible). */
    public function profile(WhatsappSetting $setting): Response;

    /** @param  array<string, mixed>  $payload  mensaje en el formato de la API (texto, archivo o plantilla) */
    public function send(WhatsappSetting $setting, array $payload): Response;

    /** Sube un archivo para poder referenciarlo en un mensaje; la respuesta trae el `id`. */
    public function upload(WhatsappSetting $setting, string $bytes, string $filename, string $mime): Response;
}
