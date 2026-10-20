<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Contacto comercial con un cliente. El WhatsApp se registra a mano. No se atribuye ninguna venta a un contacto.
 *
 * @property int $id
 * @property int $company_id
 * @property int $customer_id
 * @property int|null $user_id
 * @property string $channel
 * @property string $direction
 * @property string $outcome
 * @property Carbon $occurred_at
 * @property string|null $response
 * @property string|null $next_action
 * @property Carbon|null $next_action_at
 * @property Carbon|null $next_action_done_at
 */
#[Fillable(['company_id', 'customer_id', 'user_id', 'channel', 'direction', 'outcome', 'occurred_at', 'response', 'next_action', 'next_action_at', 'next_action_done_at', 'next_action_done_by'])]
class CustomerInteraction extends Model
{
    public const CHANNELS = ['call', 'email', 'whatsapp', 'visit', 'meeting', 'other'];

    public const DIRECTIONS = ['outbound', 'inbound'];

    /** Resultados: no incluyen «venta»; una venta solo existe cuando hay documento de venta. */
    public const OUTCOMES = ['spoke', 'no_answer', 'interested', 'quote_requested', 'callback_requested', 'not_interested', 'wrong_contact'];

    protected function casts(): array
    {
        return ['occurred_at' => 'datetime', 'next_action_at' => 'datetime', 'next_action_done_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
