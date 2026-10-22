<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Models\WhatsappConversation;
use App\Services\ConversationAccess;
use Illuminate\Auth\Access\Response;

/**
 * Qué puede hacer una persona con UNA conversación. Qué conversaciones entran en una lista lo decide `ConversationAccess::visible`
 * (la misma regla de alcance, usada aquí para ver).
 *  - Administración y gerencia: solo lectura.
 *  - Escribir: permiso de responder, rol Vendedor o Supervisor, cliente asignado y conversación en su alcance.
 *  - Asignar un número desconocido: solo supervisión.
 */
class WhatsappConversationPolicy
{
    /** Roles que pueden escribir a clientes; los demás solo leen. */
    private const WRITERS = [Role::VENDEDOR, Role::SUPERVISOR_VENTAS];

    public function __construct(private readonly ConversationAccess $access) {}

    public function view(User $user, WhatsappConversation $conversation): bool
    {
        return $this->access->canView($user, $conversation);
    }

    public function reply(User $user, WhatsappConversation $conversation): Response
    {
        $allowed = $user->hasPermission('conversations.reply')
            && in_array($user->role->slug, self::WRITERS, true)
            && $conversation->customer_id !== null
            && $this->view($user, $conversation);

        return $allowed ? Response::allow() : Response::deny('No puedes escribir en esta conversación.');
    }

    public function assign(User $user, WhatsappConversation $conversation): Response
    {
        return $user->role->slug === Role::SUPERVISOR_VENTAS && $user->hasPermission('conversations.reply')
            ? Response::allow()
            : Response::deny('Solo supervisión puede asignar números desconocidos.');
    }
}
