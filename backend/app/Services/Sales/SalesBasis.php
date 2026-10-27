<?php

declare(strict_types=1);

namespace App\Services\Sales;

use Illuminate\Contracts\Database\Query\Builder as BuilderContract;

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
     */
    public static function scope(BuilderContract $query, string $alias = 'sales_lines'): void
    {
        if (! self::corrected()) {
            return;
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
}
