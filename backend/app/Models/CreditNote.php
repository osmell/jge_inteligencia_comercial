<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Nota de crédito (devolución parcial o total de una venta a crédito). Su total se reparte entre facturas del cliente
 * mediante movimientos del libro que la referencian.
 *
 * @property int $id
 * @property int $company_id
 * @property string $series
 * @property string $number
 * @property Carbon $issued_on
 * @property string $customer_code
 * @property string $customer_name
 * @property string $subtotal
 * @property string $tax_total
 * @property string $total
 * @property string|null $observations
 * @property int|null $user_id
 */
#[Fillable(['company_id', 'series', 'number', 'issued_on', 'customer_code', 'customer_name', 'subtotal', 'tax_total', 'total', 'observations', 'user_id', 'created_at'])]
class CreditNote extends Model
{
    use Immutable;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['issued_on' => 'date:Y-m-d', 'created_at' => 'datetime', 'subtotal' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4'];
    }

    /** @return HasMany<CreditMovement, $this> */
    public function movements(): HasMany
    {
        return $this->hasMany(CreditMovement::class);
    }
}
