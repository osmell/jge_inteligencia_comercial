<?php

declare(strict_types=1);

return [
    // Criterios de rotación, cobertura, mínimos y máximos (propuesta aceptada por el responsable; se pueden ajustar aquí).
    'rotation' => [
        // Ventana de la demanda: salidas por ventas de los últimos N días, entre N.
        'demand_days' => 90,
        // Un producto con existencia y sin ventas en estos días es «sin rotación».
        'no_movement_days' => 60,
        // Mínimo = demanda diaria × (reposición + seguridad); máximo = mínimo + ciclo de compra (en días de demanda).
        'reorder_days' => 15,
        'safety_days' => 7,
        'cycle_days' => 30,
    ],
];
