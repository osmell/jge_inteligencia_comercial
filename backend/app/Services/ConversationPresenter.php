<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\WhatsappAttachment;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Forma en que un mensaje de conversación viaja al navegador (API y tiempo real). */
class ConversationPresenter
{
    /**
     * @param  Collection<int, WhatsappAttachment>  $files
     * @return array<string, mixed>
     */
    public function message(WhatsappMessage $m, $files, ?WhatsappMessage $reply, ?string $author): array
    {
        return [
            'id' => $m->id, 'direction' => $m->direction, 'type' => $m->message_type, 'body' => $m->body, 'status' => $m->status, 'error' => $m->error, 'author' => $author,
            'created_at' => $m->created_at->toIso8601String(), 'delivered_at' => $m->delivered_at?->toIso8601String(), 'read_at' => $m->read_at?->toIso8601String(),
            'reply_to' => $reply === null ? null : ['id' => $reply->id, 'direction' => $reply->direction, 'body' => mb_substr($reply->body, 0, 120)],
            'attachments' => $files->map(fn (WhatsappAttachment $a) => ['id' => $a->id, 'kind' => $a->kind, 'name' => $a->original_name, 'mime' => $a->mime, 'size' => $a->size, 'status' => $a->status, 'error' => $a->error])->values()->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function one(WhatsappMessage $m): array
    {
        $m = $m->fresh() ?? $m;                            // con cola síncrona el envío ya pudo cambiar su estado
        $reply = $m->reply_to_id === null ? null : WhatsappMessage::query()->find($m->reply_to_id);

        return $this->message($m, WhatsappAttachment::query()->where('message_id', $m->id)->get(), $reply, $m->user_id === null ? null : DB::table('users')->where('id', $m->user_id)->value('name'));
    }

    /**
     * Resumen de la conversación sin lo que depende de quién la mira (no leídos y permiso de escribir los resuelve la pantalla).
     *
     * @return array<string, mixed>
     */
    public function conversation(WhatsappConversation $c): array
    {
        $customer = $c->customer_id === null ? null : Customer::withoutGlobalScopes()->find($c->customer_id, ['id', 'code', 'name']);

        return [
            'id' => $c->id, 'company_id' => $c->company_id, 'customer' => $customer === null ? null : ['id' => $customer->id, 'code' => $customer->code, 'name' => $customer->name],
            'wa_number' => $c->wa_number, 'profile_name' => $c->profile_name, 'status' => $c->status, 'preview' => $c->last_message_preview,
            'last_message_at' => $c->last_message_at?->toIso8601String(), 'window_open' => $c->windowOpen(),
        ];
    }
}
