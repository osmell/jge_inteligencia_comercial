<?php

declare(strict_types=1);

namespace App\Integrations;

use App\Contracts\WebhookTransport;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/** Webhooks enviados por HTTP con el cliente de Laravel. */
class HttpWebhookTransport implements WebhookTransport
{
    public function post(string $url, array $headers, string $body): Response
    {
        return Http::timeout(15)->withHeaders($headers)->withBody($body, 'application/json')->post($url);
    }
}
