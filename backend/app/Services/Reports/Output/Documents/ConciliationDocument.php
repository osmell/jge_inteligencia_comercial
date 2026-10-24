<?php

declare(strict_types=1);

namespace App\Services\Reports\Output\Documents;

use App\Services\Reports\Output\ReportColumn as C;
use App\Services\Reports\Output\ReportDocument;
use App\Services\Reports\Output\ReportSection;

/** Conciliación como documento exportable. */
final class ConciliationDocument
{
    private const DIMENSIONS = ['company' => 'Compañías', 'branch' => 'Sucursales', 'seller' => 'Vendedores', 'operator' => 'Operadores'];

    private const INTEGRITY = [
        'lines_count_mismatch' => 'Documentos cuyo número de líneas no coincide con el declarado',
        'documents_without_lines' => 'Documentos sin ninguna línea',
        'line_company_mismatch' => 'Documentos con líneas de otra compañía',
        'line_branch_mismatch' => 'Documentos con líneas de otra sucursal',
        'line_date_mismatch' => 'Documentos con líneas de otra fecha',
        'line_total_mismatch' => 'Documentos con líneas donde neto + impuesto no suman su total',
    ];

    /**
     * @param  array<string, mixed>  $r  la respuesta de ConciliationReport::build
     */
    public function build(array $r, string $filters): ReportDocument
    {
        $ok = fn (bool $good): string => $good ? 'Cuadra' : 'Diferencia';
        $c = $r['consolidated'];

        $sums = array_values(array_map(fn (array $b): array => [self::DIMENSIONS[$b['dimension']] ?? $b['dimension'], $b['rows'], $b['net_total'], $b['documents'], $b['net_difference'], $b['documents_difference'], $ok($b['matches'])], $r['breakdowns']));
        $integrity = array_values(array_map(fn (array $i): array => [self::INTEGRITY[$i['code']] ?? $i['code'], $i['count'], $i['ok'] ? 'Correcto' : 'Revisar'], $r['integrity']));
        $imports = array_values(array_map(fn (array $b): array => [
            $b['name'], $b['declared_documents'], $b['registered_documents'], $b['net_comparable'] ? $b['declared_net'] : 'no comparable', $b['registered_net'], $ok($b['matches']),
        ], $r['imports']['batches']));

        return new ReportDocument(
            'Conciliación de ventas', 'conciliacion-ventas',
            ["Periodo: {$r['period']['from']} a {$r['period']['to']} ({$r['period']['days']} días)", "Filtros: {$filters}"],
            [
                'Toda cifra de venta es neta (sin IVA).',
                'Si un archivo importado traía documentos ya cargados antes, su venta neta no se compara: solo la cantidad de documentos nuevos.',
                'Esta conciliación compara tablas del propio sistema: no puede saber si el archivo de origen del sistema de facturación estaba bien.',
            ],
            [
                ['label' => 'Venta neta', 'value' => $c['net_total'], 'kind' => C::MONEY],
                ['label' => 'Impuesto', 'value' => $c['tax_total'], 'kind' => C::MONEY],
                ['label' => 'Total facturado', 'value' => $c['grand_total'], 'kind' => C::MONEY],
                ['label' => 'Documentos', 'value' => $c['documents'], 'kind' => C::INT],
                ['label' => 'Líneas', 'value' => $c['lines'], 'kind' => C::INT],
                ['label' => 'Conceptos con diferencias', 'value' => $r['problems'], 'kind' => C::INT],
                ['label' => 'Facturas de crédito enlazadas', 'value' => $r['credit']['linked'], 'kind' => C::INT],
                ['label' => 'Facturas de crédito con diferencias', 'value' => $r['credit']['mismatched'], 'kind' => C::INT],
                ['label' => 'Facturas de crédito sin enlazar', 'value' => $r['credit']['unlinked'], 'kind' => C::INT],
            ],
            [
                new ReportSection('Sumas contra el consolidado', [new C('Desglose'), new C('Filas', C::INT), new C('Venta neta', C::MONEY), new C('Documentos', C::INT), new C('Diferencia neta', C::MONEY), new C('Diferencia de documentos', C::INT), new C('Estado')], $sums),
                new ReportSection('Integridad de los documentos', [new C('Comprobación'), new C('Documentos', C::INT), new C('Estado')], $integrity),
                new ReportSection('Archivos de ventas importados', [new C('Archivo'), new C('Documentos nuevos (archivo)', C::INT), new C('Guardados', C::INT), new C('Venta neta (archivo)', C::MONEY), new C('Venta neta guardada', C::MONEY), new C('Estado')], $imports),
            ],
        );
    }
}
