<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\Immutable;
use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Movimiento del libro de inventario. Inmutable: una corrección es una reversa enlazada. Cantidad y valor con signo, en la unidad base.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property int $product_id
 * @property string $type
 * @property string|null $reason
 * @property string $quantity
 * @property string $value
 * @property Carbon $occurred_on
 * @property int|null $unit_id
 * @property string $entered_quantity
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $user_id
 * @property int|null $reverses_id
 * @property string|null $source_key
 * @property int|null $receipt_id
 */
#[Fillable(['company_id', 'branch_id', 'product_id', 'type', 'reason', 'quantity', 'value', 'occurred_on', 'unit_id', 'entered_quantity', 'reference', 'note', 'user_id', 'reverses_id', 'source_key', 'import_batch_id', 'receipt_id', 'created_at'])]
class InventoryMovement extends Model
{
    use Immutable, ScopedByOrganization;

    public const OPENING = 'opening';

    public const ENTRY = 'entry';

    public const RETURN_IN = 'return_in';

    public const TRANSFER_IN = 'transfer_in';

    public const ADJUSTMENT_IN = 'adjustment_in';

    public const SALE = 'sale';

    public const SUPPLIER_RETURN = 'supplier_return';

    public const TRANSFER_OUT = 'transfer_out';

    public const ADJUSTMENT_OUT = 'adjustment_out';

    public const REVERSAL = 'reversal';

    /** Tipos que suman existencias. */
    public const INFLOWS = [self::OPENING, self::ENTRY, self::RETURN_IN, self::TRANSFER_IN, self::ADJUSTMENT_IN];

    /** Tipos que restan existencias. */
    public const OUTFLOWS = [self::SALE, self::SUPPLIER_RETURN, self::TRANSFER_OUT, self::ADJUSTMENT_OUT];

    public const ADJUSTMENT_REASONS = ['shrinkage', 'damage', 'count_error', 'other'];

    public const RETURN_REASONS = ['defective', 'wrong_order', 'other'];

    /** Movimientos que se capturan a mano y que se pueden revertir (los traslados, en pareja; las entradas, con todo su documento). */
    public const REVERSIBLE = [self::ADJUSTMENT_IN, self::ADJUSTMENT_OUT, self::RETURN_IN, self::SUPPLIER_RETURN, self::TRANSFER_IN, self::TRANSFER_OUT, self::ENTRY];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d', 'created_at' => 'datetime', 'quantity' => 'decimal:8', 'entered_quantity' => 'decimal:8', 'value' => 'decimal:4'];
    }

    /** @return BelongsTo<Product, $this> */
    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
