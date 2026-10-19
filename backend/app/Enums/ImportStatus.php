<?php

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
}
