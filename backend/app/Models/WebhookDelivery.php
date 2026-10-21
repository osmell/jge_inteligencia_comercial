<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $event
 * @property string $dedupe_key
 * @property string $payload
 * @property string $status
 * @property int $attempt
 * @property int|null $http_status
 * @property string|null $error
 * @property Carbon $created_at
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $sent_at
 */
class WebhookDelivery extends Model
{
    public const PENDING = 'pending';

    public const SENDING = 'sending';

    public const SENT = 'sent';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['created_at' => 'datetime', 'next_attempt_at' => 'datetime', 'sent_at' => 'datetime'];
    }
}
