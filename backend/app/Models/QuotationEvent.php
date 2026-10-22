<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Historia de estados de una cotización (alimenta las cohortes de conversión).
 *
 * @property int $id
 * @property int $quotation_id
 * @property int|null $user_id
 * @property string|null $from_status
 * @property string $to_status
 * @property string|null $note
 * @property Carbon $created_at
 */
#[Fillable(['quotation_id', 'user_id', 'from_status', 'to_status', 'note', 'created_at'])]
class QuotationEvent extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
