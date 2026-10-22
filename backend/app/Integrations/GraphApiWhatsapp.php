<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Contracts\WhatsappMediaSource;
use App\Contracts\WhatsappMessaging;
use App\Models\WhatsappSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;

/** WhatsApp Cloud API de Meta (Graph API). */
class GraphApiWhatsapp implements WhatsappMediaSource, WhatsappMessaging
{
    /** Dominios desde los que Meta sirve los archivos de WhatsApp. */
    private const MEDIA_DOMAINS = ['fbsbx.com', 'facebook.com', 'fbcdn.net', 'whatsapp.net', 'whatsapp.com'];

    public function profile(WhatsappSetting $setting): Response
    {
        return $this->request($setting, 15)->get($this->url($setting, (string) $setting->phone_number_id), ['fields' => 'display_phone_number,verified_name']);
    }

    public function send(WhatsappSetting $setting, array $payload): Response
    {
        return $this->request($setting, 20)->post($this->url($setting, $setting->phone_number_id.'/messages'), $payload);
    }

    public function upload(WhatsappSetting $setting, string $bytes, string $filename, string $mime): Response
    {
        return $this->request($setting, 60)->attach('file', $bytes, $filename, ['Content-Type' => $mime])
            ->post($this->url($setting, $setting->phone_number_id.'/media'), ['messaging_product' => 'whatsapp', 'type' => $mime]);
    }

    public function info(WhatsappSetting $setting, string $mediaId): Response
    {
        return $this->request($setting, 20)->get($this->url($setting, $mediaId));
    }

    public function download(WhatsappSetting $setting, string $url): Response
    {
        // La dirección la entrega la respuesta de Meta y se llama CON el token de acceso: solo se acepta HTTPS en dominios de Meta
        // (si no, una respuesta alterada podría enviar el token a un tercero o llevar al servidor a la red interna).
        if (! self::isMetaMediaUrl($url)) {
            throw new InvalidArgumentException('La dirección del archivo no pertenece a Meta.');
        }

        return $this->request($setting, 60)->get($url);
    }

    public static function isMetaMediaUrl(string $url): bool
    {
        $parts = parse_url($url);
        $host = strtolower((string) ($parts['host'] ?? ''));

        if (strtolower((string) ($parts['scheme'] ?? '')) !== 'https' || $host === '' || isset($parts['user'])) {
            return false;
        }
        foreach (self::MEDIA_DOMAINS as $domain) {
            if ($host === $domain || str_ends_with($host, '.'.$domain)) {
                return true;
            }
        }

        return false;
    }

    private function request(WhatsappSetting $setting, int $timeout): PendingRequest
    {
        return Http::withToken((string) $setting->access_token)->timeout($timeout);
    }

    private function url(WhatsappSetting $setting, string $path): string
    {
        return "https://graph.facebook.com/{$setting->api_version}/{$path}";
    }
}
