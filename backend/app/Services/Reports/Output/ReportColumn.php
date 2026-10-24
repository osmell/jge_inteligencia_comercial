<?php

declare(strict_types=1);

namespace App\Services\Reports\Output;

/** Una columna de una tabla exportable. `leveled`: sus celdas son `['value' => …, 'level' => 'good|warn|bad|none']` (cifra con su semáforo). */
final readonly class ReportColumn
{
    public const TEXT = 'text';

    public const INT = 'int';

    public const MONEY = 'money';

    /** Porcentaje: el valor ya viene en unidades de porcentaje (12.5 = 12,5 %). */
    public const PCT = 'pct';

    /** Porcentaje de variación: se muestra con signo. */
    public const SIGNED_PCT = 'signed_pct';

    public const DECIMAL = 'decimal';

    public function __construct(public string $label, public string $kind = self::TEXT, public bool $leveled = false) {}
}
