<?php

declare(strict_types=1);

namespace App\Enums;

enum ImportStatus: string
{
    /** Archivo recibido, validación en cola. */
    case Pending = 'pending';
    case Validating = 'validating';
    /** Validado sin errores: espera confirmación del usuario. */
    case Validated = 'validated';
    /** Validado con errores: no se puede confirmar. */
    case Rejected = 'rejected';
    case Importing = 'importing';
    case Imported = 'imported';
    case Discarded = 'discarded';
    /** Falló el procesamiento (error técnico, no de datos). */
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'En cola',
            self::Validating => 'Validando',
            self::Validated => 'Validado',
            self::Rejected => 'Rechazado',
            self::Importing => 'Importando',
            self::Imported => 'Importado',
            self::Discarded => 'Descartado',
            self::Failed => 'Fallido',
        };
    }

    /** Estados en los que el lote puede descartarse. */
    public function isDiscardable(): bool
    {
        return in_array($this, [self::Validated, self::Rejected, self::Failed], true);
    }
}
