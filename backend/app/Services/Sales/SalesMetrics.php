<?php

declare(strict_types=1);

namespace App\Services\Sales;

/** Convierte una fila de agregados en las cifras que muestra la API y calcula variaciones entre periodos. */
class SalesMetrics
{
    /** @return array<string, mixed> */
    public function format(\stdClass $r): array
    {
        $net = (float) $r->net_total;
        $documents = (int) $r->documents;
        $costedNet = (float) $r->costed_net;
        $margin = $costedNet - (float) $r->costed_cost;

        return [
            'net_total' => $this->money($net),
            'tax_total' => $this->money((float) $r->tax_total),
            'grand_total' => $this->money((float) $r->grand_total),
            'discount_total' => $this->money((float) $r->discount_total),
            'discount_pct' => (float) $r->discounted_gross > 0 ? round((float) $r->discounted_amount / (float) $r->discounted_gross * 100, 2) : null,
            'units' => $this->money((float) $r->units),
            'documents' => $documents,
            'customers' => (int) $r->customers,
            'average_ticket' => $documents > 0 ? $this->money($net / $documents) : null,
            'estimated_margin' => $costedNet > 0 ? $this->money($margin) : null,
            'estimated_margin_pct' => $costedNet > 0 ? round($margin / $costedNet * 100, 2) : null,
            'cost_coverage_pct' => $net > 0 ? round($costedNet / $net * 100, 2) : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $previous
     * @return array<string, float|null>
     */
    public function changes(array $current, array $previous): array
    {
        $out = [];
        foreach (['net_total', 'tax_total', 'grand_total', 'discount_total', 'units', 'documents', 'customers', 'average_ticket', 'estimated_margin'] as $metric) {
            $before = $previous[$metric] === null ? 0.0 : (float) $previous[$metric];
            $now = $current[$metric] === null ? 0.0 : (float) $current[$metric];
            $out[$metric] = $before > 0 ? round(($now - $before) / $before * 100, 2) : null;
        }

        return $out;
    }

    public function money(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
