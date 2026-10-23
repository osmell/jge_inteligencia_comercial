<?php

declare(strict_types=1);

/**
 * Umbrales de los semáforos de los reportes de evaluación. Son valores iniciales por omisión, pendientes de validar con el cliente.
 *
 * `higher_is_better`: true → por debajo de `warn` es amarillo y por debajo de `bad` es rojo; false → desde `warn` es amarillo y desde `bad` es rojo.
 * Todos los porcentajes van de 0 a 100.
 */
return [
    'thresholds' => [
        'goal_attainment' => ['higher_is_better' => true, 'warn' => 90, 'bad' => 70],
        'growth_pct' => ['higher_is_better' => true, 'warn' => 0, 'bad' => -10],
        'discount_pct' => ['higher_is_better' => false, 'warn' => 5, 'bad' => 10],
        'below_list_pct' => ['higher_is_better' => false, 'warn' => 10, 'bad' => 25],
        'lost_customers_pct' => ['higher_is_better' => false, 'warn' => 10, 'bad' => 20],
        'top3_concentration_pct' => ['higher_is_better' => false, 'warn' => 50, 'bad' => 70],
        'overdue_pct' => ['higher_is_better' => false, 'warn' => 15, 'bad' => 30],
        'promise_kept_pct' => ['higher_is_better' => true, 'warn' => 80, 'bad' => 60],
        'quote_conversion_pct' => ['higher_is_better' => true, 'warn' => 25, 'bad' => 10],
        'overdue_follow_ups' => ['higher_is_better' => false, 'warn' => 3, 'bad' => 10],
        'whatsapp_response_minutes' => ['higher_is_better' => false, 'warn' => 30, 'bad' => 120],
    ],
];
