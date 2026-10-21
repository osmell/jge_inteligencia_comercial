<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Conversación de WhatsApp con un número de cliente. Nunca se elimina (solo se archiva).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $customer_id
 * @property string $wa_number
 * @property string|null $profile_name
 * @property string $status
 * @property string|null $last_message_preview
 * @property Carbon|null $last_message_at
 * @property Carbon|null $last_inbound_at
 * @property Carbon|null $last_away_at
 * @property Carbon $created_at
 */
class WhatsappConversation extends Model
{
    public const OPEN = 'open';

    public const ARCHIVED = 'archived';

    /** Horas durante las que se puede escribir libremente después del último mensaje del cliente. */
    public const WINDOW_HOURS = 24;

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('Las conversaciones no se eliminan: se archivan.'));
    }

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime', 'last_inbound_at' => 'datetime', 'last_away_at' => 'datetime', 'created_at' => 'datetime'];
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withoutGlobalScopes();
    }

    /** @return HasMany<WhatsappMessage, $this> */
    public function messages(): HasMany
    {
        return $this->hasMany(WhatsappMessage::class, 'conversation_id');
    }

    /** ¿Se puede escribir libremente (texto y archivos) ahora? Fuera de la ventana solo plantillas aprobadas. */
    public function windowOpen(?CarbonInterface $now = null): bool
    {
        return $this->last_inbound_at !== null && $this->last_inbound_at->gt(($now ?? now())->subHours(self::WINDOW_HOURS));
    }
}
