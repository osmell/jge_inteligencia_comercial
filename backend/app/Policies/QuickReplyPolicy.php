<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Respuestas rápidas del chat. Cada persona solo cambia las suyas (se busca por dueño); compartir con el equipo es de supervisión. */
class QuickReplyPolicy
{
    public function share(User $user): Response
    {
        return $user->role->slug === Role::SUPERVISOR_VENTAS ? Response::allow() : Response::deny('Solo supervisión puede compartir respuestas rápidas con el equipo.');
    }
}
