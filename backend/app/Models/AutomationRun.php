<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Una ejecución de una automatización.
 *
 * @property int $id
 * @property string $automation
 * @property string $trigger
 * @property string|null $period_key
 * @property string $status
 * @property int $attempt
 * @property Carbon $started_at
 * @property Carbon|null $finished_at
 * @property Carbon|null $next_retry_at
 * @property array<string, mixed>|null $result
 * @property string|null $error
 * @property int|null $user_id
 */
class AutomationRun extends Model
{
    public const RUNNING = 'running';

    public const OK = 'ok';

    public const FAILED = 'failed';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['started_at' => 'datetime', 'finished_at' => 'datetime', 'next_retry_at' => 'datetime', 'result' => 'array'];
    }
}
