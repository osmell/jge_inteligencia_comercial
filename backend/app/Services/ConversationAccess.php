<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use App\Models\WhatsappConversation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Quién ve y quién escribe en cada conversación de WhatsApp.
 *  - Vendedor: solo las de sus clientes. Supervisor: las de clientes de los vendedores que supervisa, y las «sin asignar» de sus
 *    compañías (para asignarlas). Administración y gerencia: todas las de su ámbito, **solo lectura**.
 *  - Escribir exige además el permiso de responder y ser vendedor o supervisor con la conversación en su alcance y un cliente asignado.
 * Cuando alguien que no es el vendedor responsable abre una conversación, queda en Auditoría (una vez por hora y conversación).
 */
class ConversationAccess
{
    /** Roles que pueden escribir a clientes; los demás (administración, gerencia) solo leen. */
    private const WRITERS = [Role::VENDEDOR, Role::SUPERVISOR_VENTAS];

    public function __construct(private readonly DataScope $scope, private readonly AuditLogger $audit) {}

    /** @return Builder<WhatsappConversation> */
    public function visible(User $user): Builder
    {
        $query = WhatsappConversation::query();
        if (! $user->hasPermission('conversations.view')) {
            return $query->whereRaw('1 = 0');
        }

        return match ($user->role->slug) {
            Role::VENDEDOR => $query->whereIn('whatsapp_conversations.customer_id', $this->customersOf($this->sellers($user, 'user_id'))),
            Role::SUPERVISOR_VENTAS => $query->where(fn (Builder $q) => $q
                ->whereIn('whatsapp_conversations.customer_id', $this->customersOf($this->sellers($user, 'supervisor_id')))
                ->orWhere(fn (Builder $u) => $u->whereNull('whatsapp_conversations.customer_id')->whereIn('whatsapp_conversations.company_id', $this->companyIds($user)))),
            default => $query->when($this->scope->fullCompanyIds($user) !== null, fn (Builder $q) => $q->whereIn('whatsapp_conversations.company_id', $this->scope->fullCompanyIds($user) ?? [])),
        };
    }

    public function canView(User $user, WhatsappConversation $conversation): bool
    {
        return $this->visible($user)->whereKey($conversation->id)->exists();
    }

    public function canReply(User $user, WhatsappConversation $conversation): bool
    {
        return $user->hasPermission('conversations.reply')
            && in_array($user->role->slug, self::WRITERS, true)
            && $conversation->customer_id !== null
            && $this->canView($user, $conversation);
    }

    /**
     * Personas activas que pueden ver la conversación (a quienes se les emite en tiempo real).
     *
     * @return Collection<int, User>
     */
    public function watchers(WhatsappConversation $conversation): Collection
    {
        return User::query()->where('is_active', true)->with('role.permissions')->get()->filter(fn (User $u) => $u->hasPermission('conversations.view') && $this->canView($u, $conversation))->values();
    }

    /** Deja constancia cuando alguien ajeno al vendedor responsable abre la conversación. */
    public function recordView(User $user, WhatsappConversation $conversation): void
    {
        if ($user->role->slug === Role::VENDEDOR || ! Cache::add("conversation-view:{$user->id}:{$conversation->id}", 1, now()->addHour())) {
            return;
        }
        $this->audit->record('conversations.viewed', $user, auditable: $conversation, new: ['customer_id' => $conversation->customer_id, 'wa_number' => $conversation->wa_number], companyId: $conversation->company_id);
    }

    /** @return \Illuminate\Database\Query\Builder vendedores (ids) cuyo campo $column es la persona */
    private function sellers(User $user, string $column)
    {
        return DB::table('sellers')->where($column, $user->id)->select('id');
    }

    /** @return \Illuminate\Database\Query\Builder */
    private function customersOf($sellers)
    {
        return DB::table('customers')->whereIn('seller_id', $sellers)->select('id');
    }

    /** @return list<int> */
    private function companyIds(User $user): array
    {
        return $this->scope->companyIds($user) ?? DB::table('companies')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
