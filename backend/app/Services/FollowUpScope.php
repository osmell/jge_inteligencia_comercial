<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Alcance del seguimiento comercial (agenda e indicadores). El vendedor ve solo sus clientes (alcance del modelo Customer);
 * el supervisor, los clientes de los vendedores que supervisa y lo que él mismo registró; el resto, todo su ámbito de compañías.
 */
class FollowUpScope
{
    public function isSupervisor(User $user): bool
    {
        return $user->role->slug === Role::SUPERVISOR_VENTAS;
    }

    /**
     * Filtros opcionales de compañía y vendedor sobre una consulta de clientes.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  array<string, mixed>  $opts
     */
    public function filterCustomers(Builder $query, array $opts): void
    {
        if (! empty($opts['company_id'])) {
            $query->where('customers.company_id', $opts['company_id']);
        }
        if (! empty($opts['seller_id'])) {
            $query->where('customers.seller_id', $opts['seller_id']);
        }
    }

    /**
     * Clientes de los vendedores que el usuario supervisa.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     */
    public function team(Builder $query, User $user): void
    {
        $query->whereIn('customers.seller_id', DB::table('sellers')->where('supervisor_id', $user->id)->select('id'));
    }

    /**
     * Restringe contactos o cotizaciones (modelos con customer_id y user_id) a lo que el usuario puede ver.
     *
     * @template T of Model
     *
     * @param  Builder<T>  $query
     * @param  array<string, mixed>  $opts
     */
    public function restrict(Builder $query, User $user, array $opts): void
    {
        $query->whereHas('customer', fn (Builder $c) => $this->filterCustomers($c, $opts));
        if ($this->isSupervisor($user)) {
            $query->where(fn (Builder $q) => $q->whereHas('customer', fn (Builder $c) => $this->team($c, $user))->orWhere('user_id', $user->id));
        }
    }
}
