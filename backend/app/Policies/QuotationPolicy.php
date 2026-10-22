<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Quotation;
use App\Models\User;

/**
 * Cotizaciones. Quién las ve lo decide el alcance de clientes (el vendedor solo ve las de sus clientes) al buscar el registro;
 * aquí se decide qué puede hacer con una que ya ve. Cualquier persona del equipo con el permiso puede modificar cualquier
 * cotización que vea, aunque la haya creado otra (decisión D2 de docs/25).
 */
class QuotationPolicy
{
    public function view(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission('customers.view');
    }

    /** Editar, cambiar de estado, adjuntar, quitar el adjunto, vincular factura y ver las facturas vinculables. */
    public function manage(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission('customers.view') && $user->hasPermission('interactions.manage');
    }

    public function unlink(User $user, Quotation $quotation): bool
    {
        return $user->hasPermission('customers.view') && $user->hasPermission('customers.manage');
    }

    public function download(User $user, Quotation $quotation): bool
    {
        return $this->view($user, $quotation);
    }
}
