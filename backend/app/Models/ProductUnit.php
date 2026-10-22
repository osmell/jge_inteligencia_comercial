<?php

declare(strict_types=1);

namespace App\Models;

use App\Inventory\Quantity;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Equivalencia de un producto: 1 unidad = factor_num / factor_den unidades base.
 *
 * @property int $id
 * @property int $product_id
 * @property int $unit_id
 * @property int $factor_num
 * @property int $factor_den
 */
#[Fillable(['product_id', 'unit_id', 'factor_num', 'factor_den'])]
class ProductUnit extends Model
{
    /** @return BelongsTo<Unit, $this> */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }

    public function factorText(): string
    {
        return Quantity::factorText($this->factor_num, $this->factor_den);
    }
}
