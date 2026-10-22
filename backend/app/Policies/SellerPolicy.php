<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Seller;
use App\Models\User;
use App\Services\DataScope;
use Illuminate\Auth\Access\Response;

/** Vendedores: solo se editan los de compañías sobre las que se tiene acceso completo; los demás «no existen». */
class SellerPolicy
{
    public function __construct(private readonly DataScope $scope) {}

    public function update(User $user, Seller $seller): Response
    {
        $full = $this->scope->fullCompanyIds($user);

        return $full === null || in_array($seller->company_id, $full, true) ? Response::allow() : Response::denyAsNotFound('Vendedor no encontrado.');
    }
}
