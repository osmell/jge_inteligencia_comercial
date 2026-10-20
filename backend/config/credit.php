<?php

return [
    // Límites superiores (en días desde la fecha de la factura) de los tramos de antigüedad de la cartera:
    // 1-30, 31-60, 61-90, 91-120 y más de 120 días. Lo que aún no cumple 1 día es «Al día».
    'aging_limits' => [30, 60, 90, 120],
];
