<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\ConversationBroadcast;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int|null $customer_id
 * @property int|null $template_id
 * @property int|null $conversation_id
 * @property string $direction
 * @property string $message_type
 * @property int|null $reply_to_id
 * @property string|null $to_number
 * @property string $body
 * @property string $kind
 * @property string $status
 * @property string|null $wa_message_id
 * @property int|null $user_id
 * @property string|null $client_key
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon|null $sent_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $read_at
 */
class WhatsappMessage extends Model
{
    public const SENT = 'sent';

    public const DELIVERED = 'delivered';

    public const READ = 'read';

    public const FAILED = 'failed';

    public const NOT_SENT = 'not_sent';

    public const RECEIVED = 'received';

    /** Saliente guardado, pendiente de entregar a Meta (la cola lo envía y lo reintenta). */
    public const QUEUED = 'queued';

    public $timestamps = false;

    protected $guarded = [];

    /** Los mensajes se conservan siempre: solo cambian sus estados de entrega. */
    protected static function booted(): void
    {
        static::deleting(fn () => throw new \LogicException('Los mensajes de WhatsApp no se eliminan.'));
        // Tiempo real: cualquier mensaje nuevo o cambio de estado de una conversación se emite a quienes la ven.
        static::created(fn (self $m) => $m->conversation_id === null ?: ConversationBroadcast::dispatch($m->id, 'created'));
        static::updated(function (self $m) {
            if ($m->conversation_id !== null && $m->wasChanged(['status', 'wa_message_id', 'delivered_at', 'read_at', 'error'])) {
                ConversationBroadcast::dispatch($m->id, 'updated');
            }
        });
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'sent_at' => 'datetime', 'delivered_at' => 'datetime', 'read_at' => 'datetime'];
    }
}
