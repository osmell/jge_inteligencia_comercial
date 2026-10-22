<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Webhook de salida (fila única); el secreto de firma se cifra.
 *
 * @property bool $enabled
 * @property string|null $url
 * @property string|null $secret
 * @property list<string>|null $events
 * @property Carbon|null $updated_at
 */
class WebhookSetting extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['secret'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'secret' => 'encrypted', 'events' => 'array', 'updated_at' => 'datetime'];
    }

    public static function current(): self
    {
        return self::query()->find(1) ?? new self(['id' => 1, 'enabled' => false, 'events' => []]);
    }

    public function ready(): bool
    {
        return $this->enabled && ! empty($this->url) && ! empty($this->secret);
    }
}
