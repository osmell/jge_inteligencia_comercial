<?php

return [
    // Minutos que un mensaje del cliente puede esperar respuesta antes de avisar a quien supervisa a su vendedor.
    'waiting_minutes' => (int) env('CONVERSATION_WAITING_MINUTES', 15),

    // Etiquetas disponibles para clasificar conversaciones.
    'labels' => ['Cotización', 'Pedido', 'Reclamo', 'Cobranza', 'Urgente'],

    // Cómo se conecta el navegador al servidor de tiempo real (la clave es pública; el secreto nunca sale del servidor).
    'realtime' => [
        'key' => env('REVERB_APP_KEY'), 'host' => env('REVERB_PUBLIC_HOST', 'localhost'), 'port' => (int) env('REVERB_PUBLIC_PORT', 8080), 'scheme' => env('REVERB_PUBLIC_SCHEME', 'http'),
    ],
];
