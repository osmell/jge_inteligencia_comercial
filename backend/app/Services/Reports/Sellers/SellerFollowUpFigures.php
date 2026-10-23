<?php

declare(strict_types=1);

namespace App\Services\Reports\Sellers;

use App\Models\CustomerInteraction;
use App\Models\Seller;
use App\Services\FollowUpKpis;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seguimiento comercial de cada vendedor: cotizaciones (por vendedor) y contactos/acciones registrados por el usuario vinculado al
 * vendedor. Sin usuario vinculado no hay contactos que medir: esos indicadores salen «sin dato», nunca cero.
 */
class SellerFollowUpFigures
{
    public function __construct(private readonly FollowUpKpis $kpis) {}

    /**
     * @param  Collection<int, Seller>  $sellers
     * @param  list<int>|null  $companyIds
     * @return array<int, array<string, mixed>>
     */
    public function forPeriod(Collection $sellers, ?array $companyIds, CarbonImmutable $from, CarbonImmutable $to, CarbonImmutable $now): array
    {
        $quotes = $this->quotations($companyIds, $from, $to);
        $start = $from->startOfDay();
        $end = $to->endOfDay();

        $out = [];
        foreach ($sellers as $seller) {
            $q = $quotes[$seller->id] ?? ['issued' => 0, 'converted' => 0, 'amount' => 0.0, 'converted_amount' => 0.0];
            $row = [
                'quotes_issued' => $q['issued'],
                'quotes_converted' => $q['converted'],
                'quote_conversion_pct' => $q['issued'] > 0 ? round($q['converted'] / $q['issued'] * 100, 2) : null,
                'quotes_amount' => $q['amount'],
                'has_user' => $seller->user_id !== null,
                'contacts' => null, 'actions_due' => null, 'actions_on_time_pct' => null, 'overdue_follow_ups' => null,
            ];

            if ($seller->user_id !== null) {
                $mine = CustomerInteraction::query()->where('user_id', $seller->user_id)
                    ->when($companyIds !== null, fn ($query) => $query->whereIn('company_id', (array) $companyIds));
                $due = (clone $mine)->whereBetween('next_action_at', [$start, $end])->get();
                $actions = $this->kpis->classifyActions($due, $now);
                $dueCount = $actions['on_time'] + $actions['late'] + $actions['open_overdue'];

                $row['contacts'] = (clone $mine)->whereBetween('occurred_at', [$start, $end])->count();
                $row['actions_due'] = $dueCount;
                $row['actions_on_time_pct'] = $dueCount > 0 ? round($actions['on_time'] / $dueCount * 100, 1) : null;
                // Pendientes vencidos HOY, sin importar el periodo: es lo que está por atender.
                $row['overdue_follow_ups'] = (clone $mine)->whereNull('next_action_done_at')->whereNotNull('next_action_at')->where('next_action_at', '<', $now)->count();
            }
            $out[$seller->id] = $row;
        }

        return $out;
    }

    /**
     * @param  list<int>|null  $companyIds
     * @return array<int, array{issued: int, converted: int, amount: float, converted_amount: float}>
     */
    private function quotations(?array $companyIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = DB::table('quotations')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', (array) $companyIds))
            ->whereNotNull('seller_id')->whereBetween('issued_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw("seller_id, count(*) as issued, sum(case when status = 'converted' then 1 else 0 end) as converted, sum(amount) as amount, sum(case when status = 'converted' then amount else 0 end) as converted_amount")
            ->groupBy('seller_id')->get();

        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r->seller_id] = ['issued' => (int) $r->issued, 'converted' => (int) $r->converted, 'amount' => round((float) $r->amount, 2), 'converted_amount' => round((float) $r->converted_amount, 2)];
        }

        return $out;
    }
}
