<?php

declare(strict_types=1);

namespace App\Services\Reports\Branches;

use App\Services\Sales\SalesQuery;
use Carbon\CarbonImmutable;

/**
 * Mezcla de categorías por sucursal: cuánto pesa cada categoría en la venta de cada sucursal frente a su peso en todas las sucursales
 * evaluadas. El índice es 100 cuando la sucursal vende esa categoría en la misma proporción que el conjunto; 200, el doble; 50, la mitad.
 * Solo las categorías con más venta (`LIMIT`): el resto no cabe en una tabla legible.
 */
class BranchCategoryMix
{
    public const LIMIT = 15;

    public function __construct(private readonly SalesQuery $query) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters  incluye `branch_ids`: las sucursales evaluadas
     * @return array<string, mixed>
     */
    public function build(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        [$category] = $this->query->dimensionColumns('category');
        $rows = $this->query->baseForCompanies($from, $to, $filters, $companyIds)
            ->join('branches as mix_b', 'mix_b.id', '=', 'sales_lines.branch_id')
            ->selectRaw("sales_lines.branch_id as branch_id, max(mix_b.name) as branch, {$category} as category, sum(sales_lines.net_total) as net")
            ->groupByRaw("sales_lines.branch_id, {$category}")
            ->toBase()->get();

        $branchTotals = [];
        $categoryTotals = [];
        foreach ($rows as $r) {
            $branchTotals[(int) $r->branch_id] = ($branchTotals[(int) $r->branch_id] ?? 0) + (float) $r->net;
            $categoryTotals[(string) $r->category] = ($categoryTotals[(string) $r->category] ?? 0) + (float) $r->net;
        }
        $grand = array_sum($branchTotals);
        arsort($categoryTotals);
        $top = array_slice(array_keys($categoryTotals), 0, self::LIMIT);

        $branches = $rows->unique('branch_id')->map(fn ($r) => ['id' => (int) $r->branch_id, 'name' => $r->branch])->sortBy('name')->values();
        $net = [];
        foreach ($rows as $r) {
            $net[(string) $r->category][(int) $r->branch_id] = (float) $r->net;
        }

        $categories = [];
        foreach ($top as $name) {
            $overall = $grand > 0 ? $categoryTotals[$name] / $grand : 0;
            $cells = [];
            foreach ($branches as $b) {
                $value = $net[$name][$b['id']] ?? 0.0;
                $share = ($branchTotals[$b['id']] ?? 0) > 0 ? $value / $branchTotals[$b['id']] : 0;
                $cells[] = [
                    'branch_id' => $b['id'],
                    'net_total' => round($value, 2),
                    'share_pct' => round($share * 100, 2),
                    'index' => $overall > 0 ? (int) round($share / $overall * 100) : null,
                ];
            }
            $categories[] = ['name' => $name, 'net_total' => round($categoryTotals[$name], 2), 'share_pct' => round($overall * 100, 2), 'cells' => $cells];
        }

        return ['branches' => $branches->all(), 'categories' => $categories, 'shown' => count($top), 'total_categories' => count($categoryTotals)];
    }
}
