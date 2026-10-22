<?php

declare(strict_types=1);

namespace App\Imports\Rules;

use App\Imports\Decimal;
use App\Imports\ImportRow;

/** Validación de los importes de una línea de ventas: signos, suma con impuesto, subtotal, tasa de IVA y costo. */
final class SalesAmounts
{
    /**
     * @param  array<string, mixed>  $amounts
     * @param  array<string, string>  $discounts
     */
    public static function check(ImportRow $row, ?string $units, array $amounts, array $discounts = []): void
    {
        $negativeHint = 'No se esperan valores negativos: las devoluciones, anulaciones y notas de crédito se emiten aparte.';

        if (! Decimal::isPositive($units)) {
            $row->fail('units', 'non_positive_units', 'Las unidades vendidas deben ser mayores que cero. '.(Decimal::isNegative($units) ? $negativeHint : ''));
        }
        foreach ([...$amounts, ...$discounts] as $key => $value) {
            if ($value !== null && Decimal::units($value) < 0) {
                $row->fail($key, 'negative_amount', $negativeHint);
            }
        }

        $net = Decimal::units($amounts['net_total']);
        $tax = Decimal::units($amounts['tax_total']);
        $sum = $net + $tax;
        $tolerance = (int) round((float) config('imports.amount_tolerance') * 10 ** Decimal::SCALE);
        $show = fn (int $u) => number_format($u / 10 ** Decimal::SCALE, 2, '.', '');
        $detail = "«{$row->label('net_total')}» + «{$row->label('tax_total')}» ({$show($net)} + {$show($tax)} = {$show($sum)})";

        $value = Decimal::units($amounts['sale_plus_tax']);
        if (abs($value - $sum) > $tolerance) {
            $row->fail('sale_plus_tax', 'amount_mismatch', "«{$row->label('sale_plus_tax')}» ({$show($value)}) no coincide con {$detail}.");
        }

        // Total Neto = Sub Total − Descuento (el descuento ya viene restado; no se vuelve a restar al sumar totales).
        if (isset($discounts['subtotal'])) {
            $subtotal = Decimal::units($discounts['subtotal']);
            $discount = Decimal::units($discounts['discount_total'] ?? '0');
            if (abs($subtotal - $discount - $net) > $tolerance) {
                $row->fail('net_total', 'subtotal_mismatch', sprintf(
                    '«%s» (%s) no coincide con «%s» − «%s» (%s − %s = %s).',
                    $row->label('net_total'), $show($net), $row->label('subtotal'), $row->label('discount_total'), $show($subtotal), $show($discount), $show($subtotal - $discount),
                ));
            }
        }

        $rates = config('imports.sales.tax_rates', []);
        if ($rates && ! collect((array) $rates)->contains(fn ($r) => abs($tax - (int) round($net * (float) $r)) <= $tolerance)) {
            $pct = implode(' / ', array_map(fn ($r) => rtrim(rtrim(number_format((float) $r * 100, 2), '0'), '.').' %', $rates));
            $row->fail('tax_total', 'tax_rate_mismatch', "«{$row->label('tax_total')}» ({$show($tax)}) no corresponde a «{$row->label('net_total')}» ({$show($net)}) por la tasa de IVA esperada ({$pct}).");
        }

        // Regla pendiente de confirmar con el responsable del Excel: solo advertencia.
        $unitsF = (float) $units;
        $expected = $unitsF * Decimal::units($amounts['unit_cost']) / 10 ** Decimal::SCALE;
        $actual = Decimal::units($amounts['line_cost_total']) / 10 ** Decimal::SCALE;
        if (abs($actual - $expected) > max(0.01, $unitsF * 0.0001)) {
            $row->warn('line_cost_total', 'cost_total_mismatch', sprintf(
                '«%s» (%.2f) no coincide con «%s» × «%s» (%.2f). Regla por confirmar.',
                $row->label('line_cost_total'), $actual, $row->label('units'), $row->label('unit_cost'), $expected,
            ));
        }
    }

    /**
     * Línea sin movimiento: unidades e importes de venta en cero. Se omite con aviso; si algún importe no es cero, se valida normal.
     *
     * @param  array<string, mixed>  $amounts
     * @param  array<string, string>  $discounts
     */
    public static function isEmptyLine(?string $units, array $amounts, array $discounts): bool
    {
        if ($units === null || Decimal::isPositive($units) || Decimal::isNegative($units)) {
            return false;
        }

        foreach (['net_total', 'tax_total', 'sale_plus_tax'] as $key) {
            if (Decimal::units($amounts[$key]) !== 0) {
                return false;
            }
        }

        foreach ($discounts as $value) {
            if (Decimal::units($value) !== 0) {
                return false;
            }
        }

        return true;
    }
}
