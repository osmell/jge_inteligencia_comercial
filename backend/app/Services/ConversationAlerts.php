<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Models\UserNotification;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Avisos de la campana por conversaciones: mensaje nuevo de un cliente (al vendedor responsable; los de números desconocidos, a
 * supervisión de la compañía) y cliente esperando respuesta (a quien supervisa a su vendedor). Un solo aviso sin ver por
 * conversación, para no inundar la campana mientras el cliente escribe varias líneas.
 */
class ConversationAlerts
{
    public function __construct(private readonly Notifier $notifier) {}

    public function inbound(WhatsappMessage $message): void
    {
        $c = WhatsappConversation::query()->find($message->conversation_id);
        if ($c === null) {
            return;
        }
        $customer = $c->customer_id === null ? null : Customer::withoutGlobalScopes()->find($c->customer_id);
        $name = $customer === null ? ($c->profile_name ?? '+'.$c->wa_number) : $customer->name;
        $url = '/conversaciones?c='.$c->id;
        foreach ($this->responsibles($c, $customer) as $user) {
            if (UserNotification::query()->where(['user_id' => $user->id, 'type' => 'conversation_message', 'url' => $url, 'status' => UserNotification::NEW])->exists()) {
                continue;
            }
            $this->notifier->send($user, 'conversation_message', "Mensaje nuevo de $name", mb_substr($message->body !== '' ? $message->body : 'Archivo adjunto', 0, 140), $url, "conv:{$c->id}:{$message->id}", 'info', $c->company_id);
        }
    }

    /** Avisa a supervisión de los clientes que esperan respuesta desde hace más de lo permitido; devuelve cuántos avisos creó. */
    public function waiting(?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $limit = max(1, (int) config('conversations.waiting_minutes'));
        $created = 0;
        $rows = WhatsappConversation::query()->where('status', WhatsappConversation::OPEN)->whereNotNull('customer_id')
            ->whereColumn('last_inbound_at', '>=', 'last_message_at')->where('last_message_at', '<=', $now->subMinutes($limit))->where('last_inbound_at', '>=', $now->subHours(WhatsappConversation::WINDOW_HOURS))->get();
        foreach ($rows as $c) {
            $lastId = (int) WhatsappMessage::query()->where('conversation_id', $c->id)->max('id');
            $last = WhatsappMessage::query()->find($lastId);
            if ($last === null || $last->direction !== 'in' || $last->message_type === 'reaction') {
                continue;
            }
            $customer = Customer::withoutGlobalScopes()->find($c->customer_id);
            $supervisorId = $customer?->seller_id === null ? null : DB::table('sellers')->where('id', $customer->seller_id)->value('supervisor_id');
            $supervisor = $supervisorId === null ? null : User::query()->find($supervisorId);
            if ($supervisor === null) {
                continue;
            }
            $notice = $this->notifier->send($supervisor, 'conversation_waiting', "{$customer->name} espera respuesta", "Escribió hace más de $limit minutos y nadie le ha contestado.", '/conversaciones?c='.$c->id, "conv_wait:{$c->id}:$lastId", 'warning', $c->company_id);
            $created += $notice !== null && $notice->wasRecentlyCreated ? 1 : 0;
        }

        return $created;
    }

    /** @return list<User> */
    private function responsibles(WhatsappConversation $c, ?Customer $customer): array
    {
        if ($customer === null) {
            return User::query()->where('is_active', true)->whereHas('role', fn ($q) => $q->where('slug', Role::SUPERVISOR_VENTAS))->whereHas('companies', fn ($q) => $q->where('companies.id', $c->company_id))->get()->all();
        }
        $userId = $customer->seller_id === null ? null : DB::table('sellers')->where('id', $customer->seller_id)->value('user_id');
        $user = $userId === null ? null : User::query()->find($userId);

        return $user === null ? [] : [$user];
    }
}
