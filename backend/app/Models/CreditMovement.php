<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Movimiento del libro de crédito. Inmutable: no se edita ni se borra; un error se corrige con una anulación enlazada.
 *
 * @property int $id
 * @property int $company_id
 * @property int $credit_invoice_id
 * @property string $type
 * @property string $amount importe con signo (la factura suma; abonos y notas de crédito restan)
 * @property Carbon $occurred_on
 * @property string|null $method
 * @property string|null $reference
 * @property string|null $note
 * @property int|null $user_id
 * @property int|null $reverses_id
 * @property Carbon $created_at
 */
#[Fillable(['company_id', 'credit_invoice_id', 'type', 'amount', 'occurred_on', 'method', 'reference', 'note', 'user_id', 'reverses_id', 'import_batch_id', 'created_at'])]
class CreditMovement extends Model
{
    use Immutable;

    public const INVOICE = 'invoice';

    public const PAYMENT = 'payment';

    public const CREDIT_NOTE = 'credit_note';

    public const ADJUSTMENT = 'adjustment';

    public const REVERSAL = 'reversal';

    public const TYPES = [self::INVOICE, self::PAYMENT, self::CREDIT_NOTE, self::ADJUSTMENT, self::REVERSAL];

    /** Movimientos que se pueden anular (la factura original no). */
    public const REVERSIBLE = [self::PAYMENT, self::CREDIT_NOTE, self::ADJUSTMENT];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['occurred_on' => 'date:Y-m-d', 'created_at' => 'datetime', 'amount' => 'decimal:4'];
    }

    /** @return BelongsTo<CreditInvoice, $this> */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(CreditInvoice::class, 'credit_invoice_id');
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
