<?php

use App\Automations\CreditBucketHandler;
use App\Automations\DigestDailyHandler;
use App\Automations\DigestWeeklyHandler;
use App\Automations\FollowupDueHandler;
use App\Automations\InsightHighHandler;
use App\Automations\InsightsRefreshHandler;
use App\Automations\InventoryLowHandler;
use App\Automations\MailDispatchHandler;
use App\Automations\PromiseDueHandler;
use App\Automations\PruneNotificationsHandler;
use App\Automations\QuotationExpiringHandler;

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
        'mail_dispatch' => [
            'label' => 'Envío de correos', 'description' => 'Cada hora envía los correos pendientes (avisos y resúmenes) y reintenta los que fallaron (a los 10 y 60 minutos). Sin el correo configurado, los correos quedan registrados como no enviados.',
            'frequency' => 'hourly', 'respect_window' => true, 'handler' => MailDispatchHandler::class, 'default_enabled' => true,
            'params' => [],
        ],
        'followup_due' => [
            'label' => 'Acciones de seguimiento del día y vencidas', 'description' => 'Avisa a cada vendedor y supervisor de las acciones de su agenda que vencen hoy o ya vencieron.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => FollowupDueHandler::class, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre (dentro de la ventana de avisos).']],
        ],
        'quotation_expiring' => [
            'label' => 'Cotizaciones por vencer y vencidas', 'description' => 'Avisa de las cotizaciones que vencen pronto o ya vencieron.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => QuotationExpiringHandler::class, 'default_enabled' => true,
            'params' => [
                'hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.'],
                'days_before' => ['label' => 'Anticipación', 'unit' => 'días antes del vencimiento', 'default' => 3, 'min' => 0, 'max' => 30, 'help' => 'Con cuántos días de anticipación avisar (también se avisa el día del vencimiento).'],
            ],
        ],
        'promise_due' => [
            'label' => 'Promesas de pago del día e incumplidas', 'description' => 'Avisa a quien registró la promesa y al vendedor de la factura.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => PromiseDueHandler::class, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.']],
        ],
        'credit_bucket' => [
            'label' => 'Facturas que pasan a otro tramo de cartera', 'description' => 'Avisa cuando una factura con saldo cambia de tramo de antigüedad.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => CreditBucketHandler::class, 'default_enabled' => true,
            'params' => [
                'hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.'],
                'min_balance' => ['label' => 'Saldo mínimo para avisar', 'unit' => 'córdobas', 'default' => 0, 'min' => 0, 'max' => 100000000, 'help' => 'No se avisa de facturas con un saldo menor a este.'],
            ],
        ],
        'inventory_low' => [
            'label' => 'Productos bajo el mínimo o agotados', 'description' => 'Avisa a quien consulta el inventario de los productos que necesitan reposición.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => InventoryLowHandler::class, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.']],
        ],
        'digest_daily' => [
            'label' => 'Resumen diario para gerencia', 'description' => 'Genera cada día hábil el resumen corto de cada compañía (venta de ayer contra su promedio, cartera, cotizaciones, seguimiento e inventario) y avisa a gerencia.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => DigestDailyHandler::class, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.']],
        ],
        'digest_weekly' => [
            'label' => 'Resumen semanal para gerencia', 'description' => 'Genera cada lunes el resumen de la semana anterior de cada compañía (ventas, principales clientes y productos, metas por vendedor, cartera, cotizaciones, recomendaciones e inventario) y avisa a gerencia.',
            'frequency' => 'weekly', 'respect_window' => true, 'handler' => DigestWeeklyHandler::class, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del lunes a la que corre.']],
        ],
        'insight_high' => [
            'label' => 'Recomendaciones de gravedad alta', 'description' => 'Avisa de las recomendaciones nuevas de gravedad alta de los clientes de cada persona.',
            'frequency' => 'daily', 'respect_window' => true, 'handler' => InsightHighHandler::class, 'default_enabled' => true,
            'params' => ['hour' => ['label' => 'Hora de ejecución', 'unit' => 'hora (0 a 23)', 'default' => 7, 'min' => 0, 'max' => 23, 'help' => 'Hora del día a la que corre.']],
        ],
    ],
];
