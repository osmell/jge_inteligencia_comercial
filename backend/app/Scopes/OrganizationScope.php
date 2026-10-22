<?php

declare(strict_types=1);

namespace App\Scopes;

use App\Services\DataScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Limita las consultas a las compañías/sucursales del usuario autenticado.
 * Sin usuario autenticado (consola, colas, seeders) no se aplica.
 */
class OrganizationScope implements Scope
{
    public function __construct(
        private readonly string $companyColumn,
        private readonly ?string $branchColumn = null,
    ) {}

    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user) {
            return;
        }

        $scope = app(DataScope::class)->for($user);

        if ($scope['global']) {
            return;
        }

        $table = $model->getTable();
        $company = "{$table}.{$this->companyColumn}";

        $builder->whereIn($company, $scope['companies']);

        if ($this->branchColumn && $scope['restricted']) {
            $branch = "{$table}.{$this->branchColumn}";
            $restrictedCompanies = array_keys($scope['restricted']);
            $restrictedBranches = array_merge(...array_values($scope['restricted']));

            $builder->where(fn (Builder $q) => $q
                ->whereNotIn($company, $restrictedCompanies)
                ->orWhereIn($branch, $restrictedBranches));
        }
    }
}
