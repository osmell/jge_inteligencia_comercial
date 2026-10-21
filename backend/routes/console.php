<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Requiere un proceso que ejecute el programador (php artisan schedule:work o el cron del servidor).
Schedule::command('imports:fail-stale')->hourly();
// Las automatizaciones (recálculo de inteligencia, limpieza de avisos y los avisos operativos) las gobierna el catálogo: se pueden
// pausar, cambiar de hora y consultar su registro desde la aplicación.
Schedule::command('automations:run')->everyFiveMinutes();
// Clientes de WhatsApp que llevan esperando respuesta más de lo permitido: avisa a quien supervisa a su vendedor.
Schedule::command('conversations:alert-waiting')->everyFiveMinutes();
