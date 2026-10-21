<?php

namespace Tests\Support;

use App\Automations\Handler;
use RuntimeException;

/** Cuenta cuántas veces corre; falla las primeras N veces si se le indica (para probar reintentos). */
class FakeAutomationHandler implements Handler
{
    public static int $calls = 0;

    public static int $failFirst = 0;

    public static function reset(int $failFirst = 0): void
    {
        self::$calls = 0;
        self::$failFirst = $failFirst;
    }

    public function run(array $params): array
    {
        self::$calls++;
        if (self::$calls <= self::$failFirst) {
            throw new RuntimeException('El servicio no respondió');
        }

        return ['notified' => 3, 'hour' => $params['hour'] ?? null];
    }
}
