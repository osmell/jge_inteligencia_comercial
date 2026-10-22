<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use App\Services\ConversationAccess;
use App\Services\ConversationPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Mensaje nuevo (`message.created`) o con cambios de estado o archivo (`message.updated`) en una conversación. Se emite solo a los
 * canales privados de quienes pueden ver esa conversación (el mismo criterio que la API) y después de confirmar la base de datos.
 */
class ConversationBroadcast implements ShouldBroadcast, ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public int $messageId, public string $kind) {}

    public function broadcastAs(): string
    {
        return 'message.'.$this->kind;
    }

    /** @return list<PrivateChannel> */
    public function broadcastOn(): array
    {
        $conversation = $this->conversation();
        if ($conversation === null) {
            return [];
        }

        return app(ConversationAccess::class)->watchers($conversation)->map(fn ($u) => new PrivateChannel("users.{$u->id}.conversations"))->values()->all();
    }

    /** @return array<string, mixed> */
    public function broadcastWith(): array
    {
        $message = WhatsappMessage::query()->find($this->messageId);
        $conversation = $this->conversation();
        if ($message === null || $conversation === null) {
            return [];
        }
        $presenter = app(ConversationPresenter::class);

        return ['conversation' => $presenter->conversation($conversation), 'message' => $presenter->one($message)];
    }

    private function conversation(): ?WhatsappConversation
    {
        $id = WhatsappMessage::query()->whereKey($this->messageId)->value('conversation_id');

        return $id === null ? null : WhatsappConversation::query()->find($id);
    }
}
