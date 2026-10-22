<?php

declare(strict_types=1);

namespace App\Automations;

use App\Services\InsightsRefresher;

class InsightsRefreshHandler implements Handler
{
    public function __construct(private readonly InsightsRefresher $refresher) {}

    public function run(array $params): array
    {
        $results = collect($this->refresher->refresh());

        return ['calculated' => $results->where('status', 'ok')->count(), 'insufficient' => $results->where('status', '!=', 'ok')->count(), 'companies' => $results->pluck('company')->unique()->count()];
    }
}
