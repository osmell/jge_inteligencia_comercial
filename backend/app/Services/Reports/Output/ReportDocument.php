<?php

declare(strict_types=1);

namespace App\Services\Reports\Output;

/**
 * Lo que sale de un reporte hacia Excel y PDF: título, líneas de contexto (periodo, filtros), notas de definición, cifras clave y tablas.
 * Es una forma neutra: cada formato la dibuja a su manera, así los cuatro reportes se exportan igual.
 */
final readonly class ReportDocument
{
    /**
     * @param  list<string>  $lines  periodo, comparación y filtros
     * @param  list<string>  $notes  avisos y definiciones que el lector necesita para interpretar las cifras
     * @param  list<array{label: string, value: scalar|null, kind: string}>  $stats
     * @param  list<ReportSection>  $sections
     */
    public function __construct(
        public string $title,
        public string $slug,
        public array $lines,
        public array $notes,
        public array $stats,
        public array $sections,
    ) {}
}
