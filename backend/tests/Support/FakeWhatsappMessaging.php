<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Contracts\WhatsappMessaging;
use App\Models\WhatsappSetting;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;

/** Doble del puerto de WhatsApp: responde lo que se le indique y guarda lo que recibió, sin red. */
final class FakeWhatsappMessaging implements WhatsappMessaging
{
    /** @var list<array<string, mixed>> */
    public array $sent = [];

    /** @param  array<string, mixed>  $body */
    public function __construct(private readonly int $status = 200, private readonly array $body = ['messages' => [['id' => 'wamid.fake']]]) {}

    public function profile(WhatsappSetting $setting): Response
    {
        return $this->respond(['verified_name' => 'JGE (doble)', 'display_phone_number' => '+503 0000-0000']);
    }

    public function send(WhatsappSetting $setting, array $payload): Response
    {
        $this->sent[] = $payload;

        return $this->respond($this->body);
    }

    public function upload(WhatsappSetting $setting, string $bytes, string $filename, string $mime): Response
    {
        return $this->respond(['id' => 'media.fake']);
    }

    /** @param  array<string, mixed>  $body */
    private function respond(array $body): Response
    {
        return new Response(new PsrResponse($this->status, ['Content-Type' => 'application/json'], (string) json_encode($body)));
    }
}
