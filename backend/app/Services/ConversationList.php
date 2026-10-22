<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\User;
use App\Models\WhatsappConversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Consultas de la lista de conversaciones: filtros, no leídos, etiquetas y lectura, siempre con el alcance de la persona. */
class ConversationList
{
    public function __construct(private readonly ConversationAccess $access) {}

    /**
     * Una página más una fila extra para saber si hay más. Cada fila trae `unread`.
     *
     * @param  array{q?: string|null, unassigned?: bool|null, status?: string|null}  $filters
     * @return Collection<int, WhatsappConversation>
     */
    public function page(User $user, array $filters, int $page, int $size): Collection
    {
        return $this->access->visible($user)->select('whatsapp_conversations.*')->selectSub($this->unread($user), 'unread')
            ->where('whatsapp_conversations.status', $filters['status'] ?? WhatsappConversation::OPEN)
            ->when(! empty($filters['unassigned']), fn (Builder $q) => $q->whereNull('whatsapp_conversations.customer_id'))
            ->when(! empty($filters['q']), function (Builder $q) use ($filters) {
                $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) ($filters['q'] ?? ''))).'%';
                $q->where(fn (Builder $w) => $w->whereRaw('lower(whatsapp_conversations.wa_number) like ?', [$term])->orWhereRaw('lower(coalesce(whatsapp_conversations.profile_name, \'\')) like ?', [$term])
                    ->orWhereIn('whatsapp_conversations.customer_id', DB::table('customers')->select('id')->whereRaw('lower(name) like ?', [$term])));
            })
            ->orderByRaw('whatsapp_conversations.last_message_at desc nulls last')->orderByDesc('whatsapp_conversations.id')
            ->offset(($page - 1) * $size)->limit($size + 1)->get();
    }

    /**
     * Etiquetas por conversación.
     *
     * @param  Collection<int, int>  $conversationIds
     * @return Collection<int|string, Collection<int|string, mixed>>
     */
    public function labels(Collection $conversationIds): Collection
    {
        return DB::table('conversation_labels')->whereIn('conversation_id', $conversationIds)->orderBy('label')->get()->groupBy('conversation_id')
            ->map(fn (Collection $rows) => $rows->pluck('label'));
    }

    /** Cuántas conversaciones abiertas tienen mensajes del cliente sin leer (insignia del menú). */
    public function unreadCount(User $user): int
    {
        $unread = $this->unread($user);

        return $this->access->visible($user)->where('whatsapp_conversations.status', WhatsappConversation::OPEN)->whereRaw('('.$unread->toSql().') > 0', $unread->getBindings())->count();
    }

    /** Marca como leída la conversación hasta su último mensaje. */
    public function markRead(User $user, WhatsappConversation $conversation, int $lastMessageId): void
    {
        DB::table('conversation_reads')->upsert([['user_id' => $user->id, 'conversation_id' => $conversation->id, 'last_read_message_id' => $lastMessageId, 'updated_at' => now()]], ['user_id', 'conversation_id'], ['last_read_message_id', 'updated_at']);
    }

    /**
     * Nombres de las personas autoras (id → nombre).
     *
     * @param  Collection<int, int>  $userIds
     * @return Collection<int, string>
     */
    public function authorNames(Collection $userIds): Collection
    {
        return DB::table('users')->whereIn('id', $userIds->filter())->pluck('name', 'id');
    }

    /** Subconsulta: mensajes del cliente que esta persona aún no leyó en cada conversación. */
    private function unread(User $user): QueryBuilder
    {
        return DB::table('whatsapp_messages as m')->selectRaw('count(*)')->whereColumn('m.conversation_id', 'whatsapp_conversations.id')->where('m.direction', 'in')
            ->whereRaw('m.id > coalesce((select r.last_read_message_id from conversation_reads r where r.conversation_id = whatsapp_conversations.id and r.user_id = ?), 0)', [$user->id]);
    }
}
