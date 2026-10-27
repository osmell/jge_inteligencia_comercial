<?php

declare(strict_types=1);

/**
 * Umbrales de los semáforos de los reportes de evaluación. Son valores iniciales por omisión, pendientes de validar con el cliente.
 *
 * `higher_is_better`: true → por debajo de `warn` es amarillo y por debajo de `bad` es rojo; false → desde `warn` es amarillo y desde `bad` es rojo.
 * Todos los porcentajes van de 0 a 100.
 */
return [
    /** Días desde la factura a partir de los cuales un saldo se considera vencido (el sistema no guarda plazos de crédito por cliente). */
    'overdue_after_days' => 30,

    /** Un cliente es de la cartera de un vendedor si su última compra (dentro de esta ventana) la facturó ese vendedor. */
    'portfolio_window_days' => 365,

    /** Una línea cuenta como «descuento alto» cuando el descuento es al menos este porcentaje de su subtotal. */
    'high_discount_line_pct' => 20,

    /**
     * Un documento es «atípico» si su venta neta supera Q3 + factor × (Q3 − Q1) de los documentos de su compañía en el periodo
     * (cota de valores extremos de Tukey). Con menos documentos que el mínimo no se evalúa: no hay base para decir qué es normal.
     */
    'atypical_min_documents' => 20,
    'atypical_iqr_factor' => 3,

    /** Conciliación contra el sistema de origen: una diferencia de hasta este importe es «cuadra»; hasta este porcentaje de la venta oficial es «cerca». */
    'origin_match_tolerance' => 1.0,
    'origin_near_pct' => 1.0,

    /** Filas por tabla en el PDF: pasado esto Dompdf agota la memoria (128 MB). Excel y la pantalla traen todas. */
    'pdf_max_rows' => 100,

    /** Correos de reportes: destinatarios por envío y por persona y hora (cada destinatario cuenta). */
    'email_max_recipients' => 10,
    'email_per_hour' => 30,

    'thresholds' => [
        // Los mismos cortes que la pantalla de Metas: verde desde 100 %, amarillo desde 70 %, rojo por debajo.
        'goal_attainment' => ['higher_is_better' => true, 'warn' => 100, 'bad' => 70],
        'growth_pct' => ['higher_is_better' => true, 'warn' => 0, 'bad' => -10],
        'discount_pct' => ['higher_is_better' => false, 'warn' => 5, 'bad' => 10],
        'below_list_pct' => ['higher_is_better' => false, 'warn' => 10, 'bad' => 25],
        'lost_customers_pct' => ['higher_is_better' => false, 'warn' => 10, 'bad' => 20],
        'top3_concentration_pct' => ['higher_is_better' => false, 'warn' => 50, 'bad' => 70],
        'overdue_pct' => ['higher_is_better' => false, 'warn' => 15, 'bad' => 30],
        'promise_kept_pct' => ['higher_is_better' => true, 'warn' => 80, 'bad' => 60],
        'quote_conversion_pct' => ['higher_is_better' => true, 'warn' => 25, 'bad' => 10],
        'high_discount_lines_pct' => ['higher_is_better' => false, 'warn' => 5, 'bad' => 15],
        'zero_price_lines_pct' => ['higher_is_better' => false, 'warn' => 0.5, 'bad' => 2],
        'atypical_documents_pct' => ['higher_is_better' => false, 'warn' => 2, 'bad' => 5],
        'top_seller_dependency_pct' => ['higher_is_better' => false, 'warn' => 50, 'bad' => 70],
        'no_rotation_pct' => ['higher_is_better' => false, 'warn' => 20, 'bad' => 40],
        'stockout_demand_pct' => ['higher_is_better' => false, 'warn' => 5, 'bad' => 15],
        'open_shortfalls' => ['higher_is_better' => false, 'warn' => 5, 'bad' => 20],
        'overdue_follow_ups' => ['higher_is_better' => false, 'warn' => 3, 'bad' => 10],
        'whatsapp_response_minutes' => ['higher_is_better' => false, 'warn' => 30, 'bad' => 120],
    ],
];
