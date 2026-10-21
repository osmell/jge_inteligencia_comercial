<?php

namespace App\Automations;

use App\Services\WebhookService;

/** Envía los eventos pendientes al webhook de salida y reintenta los fallidos. */
class WebhookDispatchHandler implements Handler
{
    public function __construct(private readonly WebhookService $webhook) {}

    public function run(array $params): array
    {
        return $this->webhook->dispatchPending();
    }
}
