<?php

declare(strict_types=1);

namespace App\Services;

/** Catálogo de tipos de aviso (config/notifications.php). */
class NotificationTypes
{
    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        return (array) config('notifications.types');
    }
}
