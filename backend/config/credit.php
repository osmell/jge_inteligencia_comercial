<?php

return [
    // Límites superiores (en días desde la fecha de la factura) de los tramos de antigüedad de la cartera:
    // 1-30, 31-60, 61-90, 91-120 y más de 120 días. Lo que aún no cumple 1 día es «Al día».
    'aging_limits' => [30, 60, 90, 120],

    // Calificación de crédito explicable de cada cliente (orientativa: no aprueba ni cambia límites de crédito).
    // Pesos en porcentaje; si un factor no tiene datos, su peso se reparte entre los demás. Todo se mide en la ventana indicada.
    'scoring' => [
        'weights' => ['punctuality' => 40, 'promises' => 25, 'aging' => 25, 'frequency' => 10],
        'window_days' => 365,
        // Puntuación mínima de cada banda de riesgo.
        'low_risk_from' => 75,
        'medium_risk_from' => 50,
    ],
];
