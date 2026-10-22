<?php

declare(strict_types=1);

return [
    /** Peticiones por minuto y por persona a toda la API (la pantalla de avisos y el chat consultan cada 15–60 s; una pantalla de inicio carga unas 15 solicitudes). */
    'api_per_minute' => (int) env('API_RATE_PER_MINUTE', 600),

    /** Intentos fallidos de inicio de sesión por minuto desde una misma dirección, sin importar el usuario (contra el rociado de claves). */
    /** Permite direcciones locales/privadas en integraciones de salida (webhooks). Solo para desarrollo y pruebas: en producción siempre se rechazan. */
    'allow_private_urls' => env('ALLOW_PRIVATE_URLS', env('APP_ENV') !== 'production'),

    'login_per_ip_per_minute' => (int) env('LOGIN_RATE_PER_IP', 20),
];
