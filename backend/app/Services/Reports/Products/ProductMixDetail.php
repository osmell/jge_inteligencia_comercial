<?php

declare(strict_types=1);

namespace App\Services\Reports\Products;

use App\Services\Reports\ReportPeriod;

/**
 * Detalle de un vendedor u operador: lo que más factura, cuánto pesa cada elemento frente a su equipo (índice 100 = igual proporción),
 * los huecos (categorías y líneas que el resto del equipo vende y esta persona no) y cuáles de los 20 productos estrella del equipo no vendió.
 * El equipo es la compañía de la persona: sus compañeros del mismo tipo (vendedores con vendedores, operadores con operadores).
 */
class ProductMixDetail
{
    public const GAPS = 15;

    public const STAR_PRODUCTS = 20;

    private const UNCLASSIFIED = 'Sin clasificar';

    public function __construct(private readonly ProductFigures $figures, private readonly ProductMixReport $report) {}

    /**
     * @param  'seller'|'operator'  $subject
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function for(string $subject, int $id, string $name, int $companyId, ReportPeriod $period, array $filters, string $level): array
    {
        $companyIds = [$companyId];
        $own = $filters + [$subject.'_id' => $id];

        $mine = $this->report->ranking($companyIds, $period, $own, $level);
        $team = $this->report->ranking($companyIds, $period, $filters, $level);
        $teamShare = array_column($team['items'], 'share_pct', 'key');
        foreach ($mine['items'] as &$item) {
            $theirs = $teamShare[$item['key']] ?? null;
            $item['team_share_pct'] = $theirs;
            $item['mix_index'] = $theirs !== null && $theirs > 0 && $item['share_pct'] !== null ? round($item['share_pct'] / $theirs * 100) : null;
        }
        unset($item);

        $sold = fn (string $of) => array_column(array_filter($this->figures->ranking($companyIds, $period->from, $period->to, $own, $of), fn (array $r) => $r['net_total'] > 0), 'key');
        $stars = array_slice($this->figures->ranking($companyIds, $period->from, $period->to, $filters, 'product', self::STAR_PRODUCTS), 0, self::STAR_PRODUCTS);
        $soldProducts = $sold('product');
        $missingStars = array_values(array_filter($stars, fn (array $p) => $p['net_total'] > 0 && ! in_array($p['key'], $soldProducts, true)));

        $summary = array_values(array_filter($this->report->subjectRows($companyIds, $period, $own, $subject)['rows'], fn (array $r) => $r['subject']['id'] === $id))[0] ?? null;

        return [
            'subject' => ['id' => $id, 'name' => $name, 'company_id' => $companyId, 'kind' => $subject],
            'level' => $level,
            'summary' => $summary,
            'ranking' => $mine,
            'gaps' => ['categories' => $this->gaps($companyIds, $period, $filters, $sold('category'), 'category'), 'lines' => $this->gaps($companyIds, $period, $filters, $sold('line'), 'line')],
            'star_products' => ['top' => count($stars), 'sold' => count($stars) - count($missingStars), 'missing' => $missingStars],
        ];
    }

    /**
     * Lo que el equipo vende en el nivel pedido y la persona no, de lo más vendido a lo menos.
     *
     * @param  list<int>  $companyIds
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $soldByPerson
     * @return list<array{key: string, name: string, net_total: float, share_pct: float|null, customers: int}>
     */
    private function gaps(array $companyIds, ReportPeriod $period, array $filters, array $soldByPerson, string $level): array
    {
        $team = $this->figures->ranking($companyIds, $period->from, $period->to, $filters, $level);
        $total = $this->figures->netTotal($companyIds, $period->from, $period->to, $filters);
        $missing = array_filter($team, fn (array $i) => $i['net_total'] > 0 && $i['key'] !== self::UNCLASSIFIED && ! in_array($i['key'], $soldByPerson, true));

        return array_map(
            fn (array $i) => ['key' => $i['key'], 'name' => $i['name'], 'net_total' => $i['net_total'], 'share_pct' => $total > 0 ? round($i['net_total'] / $total * 100, 2) : null, 'customers' => $i['customers']],
            array_slice(array_values($missing), 0, self::GAPS),
        );
    }
}
