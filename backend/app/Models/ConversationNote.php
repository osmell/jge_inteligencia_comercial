<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Nota interna de una conversación: solo para el equipo, nunca se envía al cliente.
 *
 * @property int $id
 * @property int $conversation_id
 * @property int|null $user_id
 * @property string $body
 * @property Carbon $created_at
 */
class ConversationNote extends Model
{
    use Immutable;

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime'];
    }
}
