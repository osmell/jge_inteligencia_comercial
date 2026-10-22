<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

/** Clientes. Qué clientes ve cada rol lo decide el alcance del modelo; aquí, qué se puede hacer con uno que ya se ve. */
class CustomerPolicy
{
    public function view(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customers.view');
    }

    /** Cambiar sus datos, su tipo, su vendedor o su facturador. */
    public function manage(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customers.manage');
    }
}
