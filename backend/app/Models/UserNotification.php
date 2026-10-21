<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Aviso interno para una persona.
 *
 * @property int $id
 * @property int $user_id
 * @property int|null $company_id
 * @property string $type
 * @property string $severity
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property string $dedupe_key
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon|null $seen_at
 * @property Carbon|null $dismissed_at
 */
class UserNotification extends Model
{
    public const NEW = 'new';

    public const SEEN = 'seen';

    public const DISMISSED = 'dismissed';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'seen_at' => 'datetime', 'dismissed_at' => 'datetime'];
    }
}
