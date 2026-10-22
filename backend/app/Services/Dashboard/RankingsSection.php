<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Services\SalesStatistics;

/** Sección «rankings» del dashboard inicial. */
final class RankingsSection extends DashboardSection
{
    public function __construct(private readonly SalesStatistics $sales) {}

    public function key(): string
    {
        return 'rankings';
    }

    public function permission(): string
    {
        return 'sales.view';
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->top($context->window, $context->filters, $context->profile);
    }

    /**
     * @param  array<string, mixed>  $w
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function top(array $w, array $filters, string $profile): array
    {
        $top = fn (string $dimension, int $limit) => array_map(fn (array $r) => ['key' => $r['key'], 'label' => $r['label'], 'net_total' => $r['net_total'], 'documents' => $r['documents']],
            $this->sales->breakdown($dimension, $w['from'], $w['to'], $filters, 'net_total', $limit)['rows']);

        return ['customers' => $top('customer', 5), 'products' => $top('product', 5)] + ($profile === 'seller' ? [] : ['sellers' => $top('seller', 10)]);
    }
}
