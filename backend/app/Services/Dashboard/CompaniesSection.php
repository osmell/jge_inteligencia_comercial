<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\User;
use App\Services\DataScope;
use App\Services\SalesStatistics;

/** Sección «companies» del dashboard inicial. */
final class CompaniesSection extends DashboardSection
{
    public function __construct(private readonly SalesStatistics $sales, private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'companies';
    }

    public function permission(): string
    {
        return 'sales.view';
    }

    /** El comparativo por compañía solo tiene sentido para quien ve más de una. */
    public function availableFor(User $user, string $profile): bool
    {
        $ids = $this->scope->companyIds($user);

        return $profile === 'company' && ($ids === null || count($ids) > 1);
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->compare($context->window, $context->filters);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function compare(array $w, array $filters): array
    {
        $previous = collect($this->sales->breakdown('company', $w['previous'][0], $w['previous'][1], $filters, 'net_total', 50)['rows'])->keyBy('key');

        return ['rows' => array_map(function (array $r) use ($previous) {
            $before = (float) ($previous[$r['key']]['net_total'] ?? 0);

            return ['company_id' => (int) $r['key'], 'company' => $r['label'], 'net_total' => $r['net_total'], 'documents' => $r['documents'], 'previous_net_total' => number_format($before, 2, '.', ''),
                'change_pct' => $before > 0 ? round(((float) $r['net_total'] - $before) / $before * 100, 1) : null];
        }, $this->sales->breakdown('company', $w['from'], $w['to'], $filters, 'net_total', 50)['rows'])];
    }
}
