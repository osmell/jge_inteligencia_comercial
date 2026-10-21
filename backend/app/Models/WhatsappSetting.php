<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Cuenta de WhatsApp Business (fila única); credenciales cifradas.
 *
 * @property bool $enabled
 * @property string|null $phone_number_id
 * @property string|null $business_account_id
 * @property string $api_version
 * @property string|null $access_token
 * @property string|null $app_secret
 * @property string|null $verify_token
 * @property Carbon|null $updated_at
 */
class WhatsappSetting extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['access_token', 'app_secret', 'verify_token'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'access_token' => 'encrypted', 'app_secret' => 'encrypted', 'verify_token' => 'encrypted', 'updated_at' => 'datetime'];
    }

    public static function current(): self
    {
        return self::query()->find(1) ?? new self(['id' => 1, 'enabled' => false, 'api_version' => 'v21.0']);
    }

    public function ready(): bool
    {
        return $this->enabled && ! empty($this->phone_number_id) && ! empty($this->access_token);
    }
}
