<?php

namespace App\Jobs;

use App\Models\WhatsappMessage;
use App\Services\ConversationSender;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/** Entrega a Meta un mensaje saliente ya guardado; reintenta ante fallos de red o de Meta sin enviar dos veces. */
class SendWhatsappMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 4;

    public int $timeout = 120;

    public function __construct(public int $messageId) {}

    /** @return list<int> */
    public function backoff(): array
    {
        return [10, 30, 90];
    }

    public function handle(ConversationSender $sender): void
    {
        $message = WhatsappMessage::query()->find($this->messageId);
        if ($message !== null) {
            $sender->deliver($message);
        }
    }

    public function failed(Throwable $e): void
    {
        $message = WhatsappMessage::query()->find($this->messageId);
        if ($message !== null) {
            app(ConversationSender::class)->giveUp($message, 'No se pudo enviar tras varios intentos.');
        }
    }
}
