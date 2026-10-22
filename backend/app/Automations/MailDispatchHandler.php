<?php

declare(strict_types=1);

namespace App\Automations;

use App\Services\MailService;

/** Envía los correos pendientes (avisos y resúmenes) y reintenta los que fallaron, dentro de la ventana de avisos. */
class MailDispatchHandler implements Handler
{
    public function __construct(private readonly MailService $mail) {}

    public function run(array $params): array
    {
        return $this->mail->dispatchPending();
    }
}
