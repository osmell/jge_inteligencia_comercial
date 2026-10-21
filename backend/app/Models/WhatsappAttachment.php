<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;

/**
 * Archivo de un mensaje, guardado en el disco privado «conversations». No se modifica ni se elimina.
 *
 * @property int $id
 * @property int $message_id
 * @property string $kind
 * @property string $path
 * @property string|null $original_name
 * @property string $mime
 * @property int $size
 */
class WhatsappAttachment extends Model
{
    use Immutable;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
