<?php

declare(strict_types=1);

namespace App\Models;

use App\Events\ConversationBroadcast;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Archivo de un mensaje, guardado en el disco privado «conversations». Se crea «pendiente» y se completa una sola vez al
 * descargarlo; después no se modifica ni se elimina.
 *
 * @property int $id
 * @property int $message_id
 * @property string $kind
 * @property string|null $path
 * @property string|null $original_name
 * @property string|null $mime
 * @property int|null $size
 * @property string $status
 * @property string|null $media_id
 * @property string|null $error
 */
class WhatsappAttachment extends Model
{
    public const PENDING = 'pending';

    public const STORED = 'stored';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = [];

    protected static function booted(): void
    {
        static::updating(function (self $a) {
            if ($a->getOriginal('status') !== self::PENDING) {
                throw new LogicException('El adjunto ya está definido y no se modifica.');
            }
        });
        // Cuando el archivo termina de descargarse (o falla), la conversación abierta se actualiza.
        static::updated(fn (self $a) => ConversationBroadcast::dispatch($a->message_id, 'updated'));
        static::deleting(fn () => throw new LogicException('Los adjuntos no se eliminan.'));
    }

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
