<?php

declare(strict_types=1);

namespace App\Services\Reports\Products;

use App\Models\Operator;
use App\Models\Seller;
use App\Services\Reports\Reconciliation;
use App\Services\Reports\ReportPeriod;

/**
 * Mezcla de productos: qué es lo que más se factura (producto, categoría, línea o grupo) y, de cada vendedor u operador, qué tan
 * amplio es el catálogo que mueve. Responde a «¿están ofreciendo todo el catálogo o repiten lo mismo?».
 *
 *  - cobertura: porcentaje de las categorías (o líneas) del catálogo de su compañía en las que tuvo venta positiva;
 *  - dependencia: porcentaje de su venta que sale de sus 5 productos principales.
 * El sistema solo sabe lo que se facturó: «no vendió» no prueba que no lo haya ofrecido.
 */
class ProductMixReport
{
    public function __construct(private readonly ProductFigures $figures, private readonly Reconciliation $reconciliation) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @param  'seller'|'operator'  $subject
     * @return array<string, mixed>
     */
    public function build(?array $companyIds, ReportPeriod $period, array $filters, string $subject, string $level): array
    {
        $ranking = $this->ranking($companyIds, $period, $filters, $level);
        ['rows' => $rows, 'net' => $total, 'documents' => $docs] = $this->subjectRows($companyIds, $period, $filters, $subject);

        return [
            ...$period->toArray(),
            'subject' => $subject,
            'level' => $level,
            'ranking' => $ranking,
            'rows' => $rows,
            'totals' => $this->totals($rows, $this->figures->catalog($companyIds), $total),
            'reconciliation' => $this->reconciliation->between($companyIds, $period, $filters, $total, $docs),
            'notes' => $period->isUnevenComparison() ? ['catalog_coverage', 'uneven_comparison'] : ['catalog_coverage'],
        ];
    }

    /**
     * Las cifras de amplitud de catálogo de cada vendedor u operador, de mayor a menor venta.
     *
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array{rows: list<array<string, mixed>>, net: float, documents: int}
     */
    public function subjectRows(?array $companyIds, ReportPeriod $period, array $filters, string $subject): array
    {
        $current = $this->figures->subjectTotals($companyIds, $period->from, $period->to, $filters, $subject);
        $categories = $this->figures->itemsSold($companyIds, $period->from, $period->to, $filters, $subject, 'category');
        $lines = $this->figures->itemsSold($companyIds, $period->from, $period->to, $filters, $subject, 'line');
        $groups = $this->figures->itemsSold($companyIds, $period->from, $period->to, $filters, $subject, 'group');
        $catalog = $this->figures->catalog($companyIds);

        $total = array_sum(array_column($current, 'net_total'));
        $rows = [];
        foreach ($this->people($companyIds, $subject, $current) as $person) {
            $c = $current[$person['id']] ?? ['net_total' => 0.0, 'documents' => 0, 'products_sold' => 0, 'top5_net' => 0.0];
            $own = $catalog[$person['company_id']] ?? ['categories' => [], 'lines' => [], 'groups' => []];
            $soldCategories = count(array_intersect($categories[$person['id']] ?? [], $own['categories']));
            $soldLines = count(array_intersect($lines[$person['id']] ?? [], $own['lines']));
            $rows[] = [
                'subject' => $person,
                'net_total' => $c['net_total'],
                'share_pct' => $total > 0 ? round($c['net_total'] / $total * 100, 2) : null,
                'documents' => $c['documents'],
                'products_sold' => $c['products_sold'],
                'categories_sold' => $soldCategories,
                'categories_catalog' => count($own['categories']),
                'category_coverage_pct' => $own['categories'] === [] ? null : round($soldCategories / count($own['categories']) * 100, 1),
                'lines_sold' => $soldLines,
                'lines_catalog' => count($own['lines']),
                'line_coverage_pct' => $own['lines'] === [] ? null : round($soldLines / count($own['lines']) * 100, 1),
                'groups_sold' => count($groups[$person['id']] ?? []),
                'top5_share_pct' => $c['net_total'] > 0 ? round($c['top5_net'] / $c['net_total'] * 100, 2) : null,
            ];
        }

        usort($rows, fn (array $a, array $b) => [$b['net_total'], $a['subject']['name']] <=> [$a['net_total'], $b['subject']['name']]);
        foreach ($rows as $i => &$row) {
            $row['rank'] = $row['net_total'] > 0 ? $i + 1 : null;
        }
        unset($row);

        return ['rows' => $rows, 'net' => $total, 'documents' => (int) array_sum(array_column($current, 'documents'))];
    }

    /**
     * Lo más facturado del nivel pedido, con su peso y cómo cambió frente al periodo de comparación.
     *
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function ranking(?array $companyIds, ReportPeriod $period, array $filters, string $level): array
    {
        $items = $this->figures->ranking($companyIds, $period->from, $period->to, $filters, $level);
        $before = [];
        foreach ($this->figures->ranking($companyIds, $period->compareFrom, $period->compareTo, $filters, $level) as $r) {
            $before[$r['key']] = $r['net_total'];
        }

        $total = $this->figures->netTotal($companyIds, $period->from, $period->to, $filters);
        $count = count($items);

        foreach ($items as $i => &$item) {
            $previous = $before[$item['key']] ?? 0.0;
            $item['rank'] = $i + 1;
            $item['share_pct'] = $total > 0 ? round($item['net_total'] / $total * 100, 2) : null;
            $item['previous_net_total'] = $previous;
            $item['growth_pct'] = $previous > 0 ? round(($item['net_total'] - $previous) / $previous * 100, 2) : null;
        }
        unset($item);

        return ['items' => $items, 'total_items' => $count, 'truncated' => $count > count($items), 'net_total' => $total];
    }

    /**
     * Las personas que se evalúan: las activas y las que vendieron en el periodo.
     *
     * @param  list<int>|null  $companyIds
     * @param  array<int, mixed>  $current
     * @return list<array{id: int, name: string, company_id: int, is_active: bool}>
     */
    private function people(?array $companyIds, string $subject, array $current): array
    {
        $model = $subject === 'seller' ? Seller::query() : Operator::query();

        return array_values($model->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', (array) $companyIds))
            ->get(['id', 'name', 'company_id', 'is_active'])
            ->filter(fn ($p) => $p->is_active || isset($current[$p->id]))
            ->map(fn ($p) => ['id' => (int) $p->id, 'name' => (string) $p->name, 'company_id' => (int) $p->company_id, 'is_active' => (bool) $p->is_active])
            ->all());
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  array<int, array{categories: list<string>, lines: list<string>, groups: list<string>}>  $catalog
     * @return array<string, mixed>
     */
    private function totals(array $rows, array $catalog, float $net): array
    {
        $coverage = array_values(array_filter(array_column($rows, 'category_coverage_pct'), fn ($v) => $v !== null));

        return [
            'people' => count($rows),
            'net_total' => round($net, 2),
            'catalog_categories' => count(array_unique(array_merge([], ...array_column($catalog, 'categories')))),
            'catalog_lines' => count(array_unique(array_merge([], ...array_column($catalog, 'lines')))),
            'average_category_coverage_pct' => $coverage === [] ? null : round(array_sum($coverage) / count($coverage), 1),
            'below_half_coverage' => count(array_filter($coverage, fn ($v) => $v < 50)),
        ];
    }
}
