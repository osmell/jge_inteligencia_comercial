<?php

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * Producto del catálogo (compañía + código). Nivel 1 = grupo general, Nivel 2 = línea, Nivel 3 = categoría.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 */
#[Fillable(['company_id', 'code', 'name', 'level1', 'level2', 'level3'])]
class Product extends Model
{
    use ScopedByOrganization;

    /** El catálogo es de la compañía: un producto se vende en varias sucursales. */
    public static function organizationBranchColumn(): ?string
    {
        return null;
    }
}
