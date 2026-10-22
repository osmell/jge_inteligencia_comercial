<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Scopes\OrganizationScope;

/**
 * Aplica el ámbito de compañía/sucursal del usuario. Modelos de fases posteriores con
 * company_id/branch_id deben usar este trait (por defecto esas columnas).
 */
trait ScopedByOrganization
{
    public static function bootScopedByOrganization(): void
    {
        static::addGlobalScope(new OrganizationScope(
            static::organizationCompanyColumn(),
            static::organizationBranchColumn(),
        ));
    }

    public static function organizationCompanyColumn(): string
    {
        return 'company_id';
    }

    public static function organizationBranchColumn(): ?string
    {
        return 'branch_id';
    }
}
