<?php

declare(strict_types=1);

namespace App\Automations;

use App\Services\Notifier;

class PruneNotificationsHandler implements Handler
{
    public function __construct(private readonly Notifier $notifier) {}

    public function run(array $params): array
    {
        return ['deleted' => $this->notifier->prune()];
    }
}
