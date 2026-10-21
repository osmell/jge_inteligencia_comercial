<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerInteraction;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;

/**
 * Deja constancia en Seguimiento del contacto por WhatsApp: cuando una persona (no el sistema) contesta a un cliente que escribió, se
 * registra un contacto «habló con el cliente» por WhatsApp. Se registra una sola vez por cliente y día: si ya hay un contacto por
 * WhatsApp ese día (hecho a mano o por otra conversación), no se agrega otro, para no contar dos veces.
 */
class ConversationLog
{
    public function contact(WhatsappMessage $message): void
    {
        if ($message->user_id === null || $message->direction !== 'out' || $message->kind !== 'conversation' || $message->conversation_id === null) {
            return;
        }
        $conversation = WhatsappConversation::query()->find($message->conversation_id);
        $customer = $conversation?->customer_id === null ? null : Customer::withoutGlobalScopes()->find($conversation->customer_id);
        if ($conversation === null || $customer === null) {
            return;
        }
        $at = $message->sent_at ?? now();
        $exists = CustomerInteraction::query()->where(['customer_id' => $customer->id, 'channel' => 'whatsapp'])->whereBetween('occurred_at', [$at->copy()->startOfDay(), $at->copy()->endOfDay()])->exists();
        if ($exists) {
            return;
        }
        $interaction = new CustomerInteraction;
        $interaction->forceFill([
            'company_id' => $customer->company_id, 'customer_id' => $customer->id, 'user_id' => $message->user_id, 'channel' => 'whatsapp', 'direction' => 'outbound', 'outcome' => 'spoke',
            'occurred_at' => $at, 'response' => 'Conversación por WhatsApp (registro automático).', 'conversation_id' => $conversation->id,
        ])->save();
    }
}
