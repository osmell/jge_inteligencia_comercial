<?php

/*
 * Tipos de aviso interno. Cada tipo dice qué permiso hace falta para recibirlo (el servidor nunca entrega un aviso a quien no podría
 * ver lo que describe) y si se entrega por omisión. Cada persona puede apagar los tipos que no quiera o pausar todos sus avisos.
 */
return [
    'types' => [
        'followup_due' => ['label' => 'Acciones de seguimiento del día o vencidas', 'permission' => 'interactions.manage', 'default' => true],
        'quotation_expiring' => ['label' => 'Cotizaciones por vencer o vencidas', 'permission' => 'customers.view', 'default' => true],
        'promise_due' => ['label' => 'Promesas de pago del día o incumplidas', 'permission' => 'credit.view', 'default' => true],
        'credit_bucket' => ['label' => 'Facturas que pasan a otro tramo de cartera', 'permission' => 'credit.view', 'default' => true],
        'inventory_low' => ['label' => 'Productos bajo el mínimo o agotados', 'permission' => 'inventory.view', 'default' => true],
        'insight_high' => ['label' => 'Recomendaciones de gravedad alta', 'permission' => 'insights.view', 'default' => true],
        'digest' => ['label' => 'Resúmenes para gerencia', 'permission' => 'digests.view', 'default' => true],
        'system' => ['label' => 'Avisos del sistema', 'permission' => null, 'default' => true],
    ],

    // Los avisos vistos o descartados se borran pasado este tiempo.
    'retention_days' => 90,
];
