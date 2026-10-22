<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Configuración del servidor de correo (fila única). La contraseña se cifra en la base de datos.
 *
 * @property int $id
 * @property bool $enabled
 * @property string|null $host
 * @property int $port
 * @property string $encryption
 * @property string|null $username
 * @property string|null $password
 * @property string|null $from_address
 * @property string|null $from_name
 * @property Carbon|null $updated_at
 */
class MailSetting extends Model
{
    public const ENCRYPTIONS = ['starttls', 'ssl', 'none'];

    public $timestamps = false;

    protected $guarded = [];

    protected $hidden = ['password'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'password' => 'encrypted', 'updated_at' => 'datetime'];
    }

    public static function current(): self
    {
        return self::query()->find(1) ?? new self(['id' => 1, 'enabled' => false, 'port' => 587, 'encryption' => 'starttls']);
    }

    /** El servidor está listo para enviar: activado y con lo mínimo configurado. */
    public function ready(): bool
    {
        return $this->enabled && $this->host !== null && $this->host !== '' && $this->from_address !== null && $this->from_address !== '';
    }
}
