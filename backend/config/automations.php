<?php

use App\Automations\InsightsRefreshHandler;
use App\Automations\PruneNotificationsHandler;

/*
 * Catálogo de automatizaciones. Cada una tiene su frecuencia, la hora a la que se ejecuta, parámetros editables (con rango), un
 * manejador que hace el trabajo (los que no tienen manejador todavía aparecen «en preparación») y si respeta la ventana de avisos
 * (las que envían avisos a personas no corren fuera de ella; el mantenimiento sí). El estado (activa o pausada) y los parámetros
 * vigentes se guardan en la base de datos; aquí están los valores por omisión.
 */
return [
    // Ventana en la que las automatizaciones que envían avisos pueden ejecutarse: de lunes a sábado, entre estas horas.
    'window' => ['start' => 7, 'end' => 19],

    // Reintentos de una ejecución fallida: espera en minutos antes de cada reintento (3 intentos en total).
    'retry_minutes' => [5, 15],

    // Una ejecución «en curso» más antigua que esto se considera interrumpida.
    'stale_after_minutes' => 30,

    'automations' => [
        'insights_refresh' => [
            'label' => 'Recálculo de la inteligencia comercial', 'description' => 'Recalcula cada noche segmentos RFM, concentración, cohortes, venta cruzada, riesgo de inactividad, cambios anormales y estacionalidad, y las recomendaciones que de ahí salen.',
            'frequency' => 'daily', 'respect_window' => false, 'handler' => InsightsRefreshHandler::class, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 2, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.']],
        ],
        'notifications_prune' => [
            'label' => 'Limpieza de avisos antiguos', 'description' => 'Borra cada semana los avisos internos vistos o descartados con más de 90 días.',
            'frequency' => 'weekly', 'respect_window' => false, 'handler' => PruneNotificationsHandler::class, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 3, 'min' => 0, 'max' => 23, 'help' => 'Hora del lunes a la que corre.']],
        ],
        'followup_due' => [
            'label' => 'Acciones de seguimiento del día y vencidas', 'description' => 'Avisa a cada vendedor y supervisor de las acciones de su agenda que vencen hoy o ya vencieron.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => null, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre (dentro de la ventana de avisos).']],
        ],
        'quotation_expiring' => [
            'label' => 'Cotizaciones por vencer y vencidas', 'description' => 'Avisa de las cotizaciones que vencen pronto o ya vencieron.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => null, 'default_enabled' => true,
            'params' => [
                'hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.'],
                'days_before' => ['label' => 'Anticipación', 'unit' => 'días antes del vencimiento', 'default' => 3, 'min' => 0, 'max' => 30, 'help' => 'Con cuántos días de anticipación avisar (también se avisa el día del vencimiento).'],
            ],
        ],
        'promise_due' => [
            'label' => 'Promesas de pago del día e incumplidas', 'description' => 'Avisa a quien registró la promesa y al vendedor de la factura.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => null, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.']],
        ],
        'credit_bucket' => [
            'label' => 'Facturas que pasan a otro tramo de cartera', 'description' => 'Avisa cuando una factura con saldo cambia de tramo de antigüedad.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => null, 'default_enabled' => true,
            'params' => [
                'hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.'],
                'min_balance' => ['label' => 'Saldo mínimo para avisar', 'unit' => 'córdobas', 'default' => 0, 'min' => 0, 'max' => 100000000, 'help' => 'No se avisa de facturas con un saldo menor a este.'],
            ],
        ],
        'inventory_low' => [
            'label' => 'Productos bajo el mínimo o agotados', 'description' => 'Avisa a quien consulta el inventario de los productos que necesitan reposición.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => null, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.']],
        ],
        'insight_high' => [
            'label' => 'Recomendaciones de gravedad alta', 'description' => 'Avisa de las recomendaciones nuevas de gravedad alta de los clientes de cada persona.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => null, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.']],
        ],
    ],
];
