<?php

namespace App\Automations;

/**
 * Trabajo de una automatización. Debe ser idempotente: el ejecutor puede reintentarlo tras un fallo, así que repetirlo no puede
 * duplicar avisos ni operaciones (los avisos usan clave de deduplicación).
 */
interface Handler
{
    /**
     * @param  array<string, int>  $params  parámetros vigentes de la automatización
     * @return array<string, mixed> resumen de lo realizado (se guarda en el registro de ejecución)
     */
    public function run(array $params): array;
}
