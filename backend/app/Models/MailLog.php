<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Un correo del sistema y lo que pasó con él.
 *
 * @property int $id
 * @property int|null $user_id
 * @property string|null $to_address
 * @property string $kind
 * @property string $subject
 * @property string $body
 * @property string|null $action_url
 * @property string|null $dedupe_key
 * @property string $status
 * @property int $attempt
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 */
class MailLog extends Model
{
    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public const NOT_SENT = 'not_sent';

    public $timestamps = false;

    protected $table = 'mail_log';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
