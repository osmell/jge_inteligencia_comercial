<?php

declare(strict_types=1);

namespace App\Contracts;

use Illuminate\Http\Client\Response;

/** Puerto de salida de los webhooks: una petición POST firmada por quien llama. */
interface WebhookTransport
{
    /** @param  array<string, string>  $headers */
    public function post(string $url, array $headers, string $body): Response;
}
