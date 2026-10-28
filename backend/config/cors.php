<?php

declare(strict_types=1);

// `FRONTEND_URL`: una o varias direcciones separadas por coma. Un `*` dentro de una dirección vale por un tramo (sin puntos ni dos puntos), por
// ejemplo `http://192.168.0.*:5173` acepta todo el segmento 192.168.0.0/24; esas se comparan como patrón y las demás, tal cual.
$origins = array_values(array_filter(array_map('trim', explode(',', (string) env('FRONTEND_URL', 'http://localhost:5173')))));
$exact = array_values(array_filter($origins, fn (string $o) => ! str_contains($o, '*')));
$patterns = array_map(
    fn (string $o) => '#^'.str_replace('\\*', '[A-Za-z0-9-]+', preg_quote($o, '#')).'$#',
    array_values(array_filter($origins, fn (string $o) => str_contains($o, '*'))),
);

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => $exact,

    'allowed_origins_patterns' => $patterns,

    'allowed_headers' => ['*'],

    // El navegador solo deja leer a la interfaz (que vive en otro origen) los encabezados que se exponen: sin este, todo archivo que se
    // descarga perdía el nombre que le da el servidor y se guardaba con uno genérico.
    'exposed_headers' => ['Content-Disposition'],

    'max_age' => 0,

    'supports_credentials' => true,

];
