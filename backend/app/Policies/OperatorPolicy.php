<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Operator;
use App\Models\User;
use App\Services\DataScope;
use Illuminate\Auth\Access\Response;

/** Facturadores: solo se editan los de compañías sobre las que se tiene acceso completo; los demás «no existen». */
class OperatorPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function update(User $user, Operator $operator): Response
    {
        $full = $this->scope->fullCompanyIds($user);

        return $full === null || in_array($operator->company_id, $full, true) ? Response::allow() : Response::denyAsNotFound('Facturador no encontrado.');
    }
}
