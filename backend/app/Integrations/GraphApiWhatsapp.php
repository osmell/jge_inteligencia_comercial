<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Contracts\WhatsappMediaSource;
use App\Contracts\WhatsappMessaging;
use App\Models\WhatsappSetting;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** WhatsApp Cloud API de Meta (Graph API). */
class GraphApiWhatsapp implements WhatsappMediaSource, WhatsappMessaging
{
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
        return $this->request($setting, 60)->get($url);
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
