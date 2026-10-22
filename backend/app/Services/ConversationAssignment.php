<?php

declare(strict_types=1);

namespace App\Services;

use App\Events\ConversationBroadcast;
use App\Models\Customer;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Models\WhatsappMessage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Asignación de un número desconocido a un cliente del equipo de quien supervisa. */
class ConversationAssignment
{
    public function __construct(private readonly AuditLogger $audit, private readonly WhatsappService $wa) {}

    /** Clientes de los vendedores que supervisa la persona. */
    public function team(User $supervisor): Builder
    {
        return DB::table('customers')->whereIn('customers.seller_id', DB::table('sellers')->where('supervisor_id', $supervisor->id)->select('id'));
    }

    /**
     * Clientes del equipo en la misma compañía, filtrados por nombre o código.
     *
     * @return Collection<int, \stdClass>
     */
    public function candidates(User $supervisor, int $companyId, ?string $search): Collection
    {
        $term = '%'.str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower((string) $search)).'%';

        return $this->team($supervisor)->where('customers.company_id', $companyId)
            ->when($search !== null && $search !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(customers.name) like ?', [$term])->orWhereRaw('lower(customers.code) like ?', [$term])))
            ->orderBy('customers.name')->limit(15)->get(['customers.id', 'customers.code', 'customers.name', 'customers.whatsapp']);
    }

    /** Asigna la conversación al cliente; devuelve false si el cliente no es del equipo en la misma compañía. */
    public function assign(User $supervisor, WhatsappConversation $conversation, int $customerId): bool
    {
        $found = $this->team($supervisor)->where('customers.company_id', $conversation->company_id)->where('customers.id', $customerId)->first(['customers.id']);
        if ($found === null) {
            return false;
        }
        $customer = Customer::withoutGlobalScopes()->findOrFail($found->id);
        DB::transaction(function () use ($conversation, $customer, $supervisor) {
            $conversation->forceFill(['customer_id' => $customer->id])->save();
            if ($this->wa->number($customer->whatsapp) === '') {
                $customer->forceFill(['whatsapp' => '+'.$conversation->wa_number])->save();      // para poder usar plantillas con este número
            }
            DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->whereNull('customer_id')->update(['customer_id' => $customer->id]);
            $this->audit->record('conversations.assigned', $supervisor, auditable: $conversation, new: ['customer_id' => $customer->id, 'wa_number' => $conversation->wa_number], companyId: $conversation->company_id);
        });
        self::refreshLists($conversation);

        return true;
    }

    /** Las listas de quienes ya ven la conversación se actualizan. */
    public static function refreshLists(WhatsappConversation $conversation): void
    {
        $last = WhatsappMessage::query()->where('conversation_id', $conversation->id)->max('id');
        if ($last !== null) {
            ConversationBroadcast::dispatch((int) $last, 'updated');
        }
    }
}
