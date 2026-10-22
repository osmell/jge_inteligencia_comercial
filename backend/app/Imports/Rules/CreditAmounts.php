<?php

declare(strict_types=1);

namespace App\Imports\Rules;

use App\Imports\Decimal;
use App\Imports\ImportRow;

/** Validación de los importes de una factura de crédito: signos, total positivo y total = subtotal − descuento + impuesto. */
final class CreditAmounts
{
    /** @param  array<string, mixed>  $amounts */
    public static function check(ImportRow $row, array $amounts): void
    {
        $negativeHint = 'No se esperan valores negativos: las devoluciones se registran como notas de crédito.';
        foreach ($amounts as $key => $value) {
            if ($value !== null && Decimal::isNegative($value)) {
                $row->fail($key, 'negative_amount', $negativeHint);
            }
        }
        if (! Decimal::isPositive($amounts['total'])) {
            $row->fail('total', 'non_positive_total', 'El total de la factura debe ser mayor que cero.');
        }

        $tolerance = (int) round((float) config('imports.amount_tolerance') * 10 ** Decimal::SCALE);
        $show = fn (int $u) => number_format($u / 10 ** Decimal::SCALE, 2, '.', '');
        $expected = Decimal::units($amounts['subtotal']) - Decimal::units($amounts['discount_total']) + Decimal::units($amounts['tax_total']);
        $total = Decimal::units($amounts['total']);
        if (abs($total - $expected) > $tolerance) {
            $row->fail('total', 'amount_mismatch', sprintf(
                '«%s» (%s) no coincide con «%s» − «%s» + «%s» (%s).',
                $row->label('total'), $show($total), $row->label('subtotal'), $row->label('discount_total'), $row->label('tax_total'), $show($expected),
            ));
        }
    }
}
