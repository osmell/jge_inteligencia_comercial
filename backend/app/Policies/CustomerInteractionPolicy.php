<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CustomerInteraction;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/** Contactos de seguimiento: se corrigen por quien los registró o por quien gestiona clientes. */
class CustomerInteractionPolicy
{
    public function update(User $user, CustomerInteraction $interaction): Response
    {
        return $interaction->user_id === $user->id || $user->hasPermission('customers.manage')
            ? Response::allow()
            : Response::deny('Solo quien registró el contacto puede corregirlo.');
    }
}
