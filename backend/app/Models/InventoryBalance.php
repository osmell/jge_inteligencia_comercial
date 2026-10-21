<?php

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saldo de un producto en una sucursal (cantidad en unidad base y valor). Solo lo escribe el libro de inventario.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $product_id
 * @property string $quantity
 * @property string $value
 */
class InventoryBalance extends Model
{
    use ScopedByOrganization;

    public $timestamps = false;

    protected $guarded = ['*'];

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Company, $this> */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
