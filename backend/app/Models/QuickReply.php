<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Respuesta rápida del chat (personal o compartida por supervisión). Las consultas viven en `QuickReplies`. */
class QuickReply extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return ['shared' => 'boolean', 'is_active' => 'boolean'];
    }
}
