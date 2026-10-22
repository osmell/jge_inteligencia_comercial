<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Parte de una venta que no se pudo descontar del inventario por falta de existencias (o de producto/unidad base).
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int|null $product_id
 * @property int $sales_line_id
 * @property string $product_code
 * @property Carbon $issued_on
 * @property string $requested
 * @property string $applied
 * @property string $missing
 * @property string $reason
 * @property Carbon|null $resolved_at
 */
#[Fillable(['company_id', 'branch_id', 'product_id', 'sales_line_id', 'product_code', 'issued_on', 'requested', 'applied', 'missing', 'reason', 'resolved_at', 'resolved_by'])]
class InventoryShortfall extends Model
{
    use ScopedByOrganization;

    public const INSUFFICIENT = 'insufficient';

    public const NO_PRODUCT = 'no_product';

    public const NO_BASE_UNIT = 'no_base_unit';

    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'resolved_at' => 'datetime', 'requested' => 'decimal:8', 'applied' => 'decimal:8', 'missing' => 'decimal:8'];
    }

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
}
