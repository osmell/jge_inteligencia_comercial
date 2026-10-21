<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Hay un aviso nuevo para la persona: la campana se actualiza sin esperar al siguiente sondeo. Solo lleva el tipo, nunca el contenido. */
class NoticeCreated implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $userId, public string $type) {}

    public function broadcastAs(): string
    {
        return 'notice.created';
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        return [new PrivateChannel("users.{$this->userId}.notifications")];
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        return ['type' => $this->type];
    }
}
