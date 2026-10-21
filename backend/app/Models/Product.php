<?php

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Producto del catálogo (compañía + código). Nivel 1 = grupo general, Nivel 2 = línea, Nivel 3 = categoría.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property int|null $base_unit_id
 */
#[Fillable(['company_id', 'code', 'name', 'level1', 'level2', 'level3', 'base_unit_id'])]
class Product extends Model
{
    use ScopedByOrganization;

    /** El catálogo es de la compañía: un producto se vende en varias sucursales. */
    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    /** @return BelongsTo<Unit, $this> */
    public function baseUnit(): BelongsTo
    {
        return $this->belongsTo(Unit::class, 'base_unit_id');
    }

    /** @return HasMany<ProductUnit, $this> */
    public function equivalences(): HasMany
    {
        return $this->hasMany(ProductUnit::class);
    }
}
