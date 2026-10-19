<?php

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cliente (datos maestros). Se identifica por compañía + código de origen.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $branch_id
 * @property string $code
 * @property string $name
 */
#[Fillable(['company_id', 'branch_id', 'code', 'name', 'trade_name', 'contact_name', 'phone', 'whatsapp', 'email', 'address', 'source'])]
class Customer extends Model
{
    use ScopedByOrganization;

    /**
     * Los datos maestros se limitan por compañía, no por sucursal: un cliente puede comprar en varias. Las cifras de venta
     * de la ficha sí respetan las sucursales del usuario. La visibilidad por rol (p. ej. un vendedor solo ve sus clientes)
     * llega con las asignaciones.
     */
    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
