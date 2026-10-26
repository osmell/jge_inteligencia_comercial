<?php

declare(strict_types=1);

namespace App\Services\Reports\Output;

/** Texto de los avisos de cada reporte (los mismos que muestra la pantalla). */
final class ReportNotes
{
    private const TEXT = [
        'portfolio_and_credit_as_of_today' => 'La cartera de clientes y la cobranza son a la fecha de hoy, no del periodo elegido.',
        'goal_not_comparable_with_branch_filter' => 'Con una sucursal elegida no se califica la meta: la venta es parcial y la meta es de toda la compañía.',
        'uneven_comparison' => 'El periodo de comparación dura distinto que el elegido: los cambios no son directamente comparables.',
        'no_operator_cancellations' => 'El sistema no sabe qué operador anuló o corrigió un documento: el control de captura se basa solo en lo que traen los archivos de venta.',
        'portfolio_credit_inventory_as_of_today' => 'La cartera de clientes, la cobranza y las existencias son a la fecha de hoy, no del periodo elegido. Las existencias se cuentan en productos, sin costos ni valuación.',
        'catalog_coverage' => 'La cobertura se mide contra las categorías y líneas del catálogo de productos de la compañía, y solo cuenta la venta neta positiva (una devolución no cuenta). El sistema sabe lo que se facturó, no lo que se ofreció: «no vendió» no prueba que no lo haya ofrecido.',
    ];

    public const COMMON = 'Toda cifra de venta es neta (sin IVA). Los umbrales de los semáforos son valores iniciales por omisión.';

    /**
     * @param  list<string>  $codes
     * @return list<string>
     */
    public static function for(array $codes): array
    {
        return [...array_map(fn (string $c): string => self::TEXT[$c] ?? $c, $codes), self::COMMON];
    }

    /** @return array<string, string> */
    public static function levelLabels(): array
    {
        return ['good' => 'Bien', 'warn' => 'Atención', 'bad' => 'Crítico', 'none' => 'Sin dato'];
    }
}
