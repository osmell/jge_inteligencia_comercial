<?php

declare(strict_types=1);

namespace App\Services\Sales;

use App\Models\SalesLineCorrection;
use Illuminate\Support\Facades\DB;

/**
 * Corrección del neto de las líneas vendidas por equivalencia (docs/30_CONCILIACION_FACTURACION.md).
 *
 * Con unidades menores a 1, «Precio Unit.Vta.» ya es el precio de la porción, pero el reporte del sistema de origen multiplica otra vez
 * por la fracción al calcular Total Neto. El neto real de la línea es entonces el precio unitario (Total Neto ÷ Unidades).
 * Se aplica a líneas con unidades entre 0 y 1 (sin incluir 1), importe mayor a cero y precio unitario de 1 o más: las líneas con precio
 * simbólico (catalizador, reductor, thinner) no se tocan aquí.
 *
 * No modifica sales_lines: escribe en sales_line_corrections. Es idempotente (solo agrega las líneas que aún no tienen corrección).
 * Probada con agosto de 2026: deja la venta neta a 0,34 % de la oficial del sistema de origen.
 */
final class NetCorrection
{
    public const RULE_VERSION = 1;

    /** @return int líneas corregidas en esta ejecución */
    public function run(?int $companyId = null): int
    {
        $query = DB::table('sales_lines as l')
            ->where('l.units', '>', 0)->where('l.units', '<', 1)
            ->where('l.unit_price', '>=', 1)->where('l.net_total', '>', 0)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('sales_line_corrections as c')->whereColumn('c.sales_line_id', 'l.id'))
            ->when($companyId, fn ($q) => $q->where('l.company_id', $companyId))
            ->select([
                'l.id', 'l.company_id', 'l.branch_id', 'l.issued_on',
                DB::raw("'".SalesLineCorrection::PORTION."'"),
                'l.unit_price',
                DB::raw('round(l.unit_price * l.tax_total / l.net_total, 4)'),
                DB::raw(self::RULE_VERSION),
                DB::raw('current_timestamp'), DB::raw('current_timestamp'),
            ]);

        return DB::table('sales_line_corrections')->insertUsing(
            ['sales_line_id', 'company_id', 'branch_id', 'issued_on', 'cause', 'corrected_net', 'corrected_tax', 'rule_version', 'created_at', 'updated_at'],
            $query,
        );
    }
}
