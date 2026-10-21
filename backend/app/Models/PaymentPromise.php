<?php

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use App\Services\PaymentPromises;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Promesa de pago de un cliente. El estado (abierta, cumplida, incumplida, cancelada) se deriva en {@see PaymentPromises}.
 *
 * @property int $id
 * @property int $company_id
 * @property string $customer_code
 * @property string $customer_name
 * @property Carbon $made_on
 * @property Carbon $promised_on
 * @property string $amount
 * @property string|null $note
 * @property int|null $user_id
 * @property Carbon|null $cancelled_at
 * @property string|null $cancel_reason
 */
#[Fillable(['company_id', 'customer_code', 'customer_name', 'made_on', 'promised_on', 'amount', 'note', 'user_id', 'cancelled_at', 'cancelled_by', 'cancel_reason'])]
class PaymentPromise extends Model
{
    use ScopedByOrganization;

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['made_on' => 'date:Y-m-d', 'promised_on' => 'date:Y-m-d', 'amount' => 'decimal:4', 'cancelled_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
