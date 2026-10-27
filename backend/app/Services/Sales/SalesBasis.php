<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Support\Facades\DB;

/**
 * Base de las cifras de venta: «corregida» (por omisión) o «tal como se importó».
 *
 * La corregida usa el neto corregido de las líneas por equivalencia (`sales_line_corrections`) y deja fuera las ventas de facturas
 * anuladas (`sales_document_voids`); sin corrección, cada importe es el de la línea. La importada usa los importes del archivo tal cual.
 * Se elige con el parámetro `basis=imported` de la petición; fuera de una petición (colas, comandos) es la corregida.
 * Toda consulta de importes de venta debe pasar por `scope` y por `net`/`tax`/`grand` (docs/30_CONCILIACION_FACTURACION.md).
 */
final class SalesBasis
{
    public const IMPORTED = 'imported';

    public const CORRECTED = 'corrected';

    public static function mode(): string
    {
        $basis = request()->query('basis');

        return $basis === self::IMPORTED ? self::IMPORTED : self::CORRECTED;
    }

    public static function corrected(): bool
    {
        return self::mode() === self::CORRECTED;
    }

    /**
     * Agrega a la consulta lo que necesitan las expresiones de esta clase: la corrección de cada línea y la exclusión de las facturas anuladas.
     *
     * @param  BuilderContract  $query  consulta que ya tiene `sales_lines` (o su alias) en el FROM o en un JOIN
     * @param  literal-string  $alias
     * @param  bool  $withCost  también la reasignación del costo de los componentes (solo si la consulta usa `cost`)
     */
    public static function scope(BuilderContract $query, string $alias = 'sales_lines', bool $withCost = false): void
    {
        if (! self::corrected()) {
            return;
        }

        if ($withCost) {
            $query->leftJoin("sales_line_component_costs as cc_{$alias}", "cc_{$alias}.component_line_id", '=', "{$alias}.id");
            $query->leftJoinSub(
                DB::table('sales_line_component_costs')->selectRaw('main_line_id, sum(cost) as extra')->groupBy('main_line_id'),
                "mx_{$alias}",
                "mx_{$alias}.main_line_id",
                '=',
                "{$alias}.id",
            );
        }

        $query->leftJoin("sales_line_corrections as cx_{$alias}", "cx_{$alias}.sales_line_id", '=', "{$alias}.id");
        $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from("sales_document_voids as vd_{$alias}")->whereColumn("vd_{$alias}.sales_document_id", "{$alias}.sales_document_id"));
    }

    /**
     * Neto de la línea (SQL).
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function net(string $alias = 'sales_lines'): string
    {
        return self::corrected() ? "coalesce(cx_{$alias}.corrected_net, {$alias}.net_total)" : "{$alias}.net_total";
    }

    /**
     * Impuesto de la línea (SQL).
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function tax(string $alias = 'sales_lines'): string
    {
        return self::corrected() ? "coalesce(cx_{$alias}.corrected_tax, {$alias}.tax_total)" : "{$alias}.tax_total";
    }

    /**
     * Neto más impuesto de la línea (SQL).
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function grand(string $alias = 'sales_lines'): string
    {
        return self::corrected() ? "coalesce(cx_{$alias}.corrected_net + cx_{$alias}.corrected_tax, {$alias}.grand_total)" : "{$alias}.grand_total";
    }

    /**
     * Costo de la línea (SQL), con el de sus componentes sumado a la línea principal y en cero en las líneas de componente.
     * Solo en consultas con `scope(..., withCost: true)`.
     *
     * @param  literal-string  $alias
     * @return literal-string
     */
    public static function cost(string $alias = 'sales_lines'): string
    {
        return self::corrected()
            ? "(case when cc_{$alias}.id is not null then 0 else {$alias}.line_cost_total + coalesce(mx_{$alias}.extra, 0) end)"
            : "{$alias}.line_cost_total";
    }
}
