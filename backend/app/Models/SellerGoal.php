<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Meta mensual de venta neta de un vendedor.
 *
 * @property int $id
 * @property int $seller_id
 * @property string $amount
 * @property int $days_off
 * @property string $source
 */
#[Fillable(['seller_id', 'month', 'amount', 'days_off', 'source', 'updated_by'])]
class SellerGoal extends Model
{
    protected function casts(): array
    {
        return ['month' => 'date:Y-m-d', 'amount' => 'decimal:2', 'days_off' => 'integer'];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
