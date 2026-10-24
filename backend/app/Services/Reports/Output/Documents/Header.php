<?php

declare(strict_types=1);

namespace App\Services\Reports\Output\Documents;

/** Líneas de contexto comunes: periodo, comparación y filtros. */
final class Header
{
    private const MODES = ['previous' => 'periodo anterior', 'last_year' => 'mismo periodo del año pasado', 'custom' => 'otro periodo'];

    /**
     * @param  array<string, mixed>  $report  trae `period` y, si compara, `comparison`
     * @return list<string>
     */
    public static function lines(array $report, string $filters): array
    {
        $lines = ["Periodo: {$report['period']['from']} a {$report['period']['to']} ({$report['period']['days']} días)"];
        if (isset($report['comparison'])) {
            $c = $report['comparison'];
            $mode = self::MODES[$c['mode']] ?? $c['mode'];
            $lines[] = "Comparado con ({$mode}): {$c['from']} a {$c['to']} ({$c['days']} días)";
        }
        $lines[] = "Filtros: {$filters}";

        return $lines;
    }
}
