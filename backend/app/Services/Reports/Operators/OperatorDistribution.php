<?php

declare(strict_types=1);

namespace App\Services\Reports\Operators;

use App\Models\Operator;
use App\Services\Sales\SalesBasis;
use App\Services\Sales\SalesQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * A quién y cuándo factura un operador: vendedores y sucursales a los que factura, y su actividad por día, día de la semana y hora
 * (la hora solo existe en los documentos cuyo archivo la traía).
 */
class OperatorDistribution
{
    public function __construct(private readonly SalesQuery $query) {}

    /**
     * @param  list<int>|null  $companyIds
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function for(?array $companyIds, Operator $operator, CarbonImmutable $from, CarbonImmutable $to, array $filters): array
    {
        $base = fn () => $this->query->baseForCompanies($from, $to, ['operator_id' => $operator->id] + $filters, $companyIds);

        $sellers = $base()->join('sellers as dist_s', 'dist_s.id', '=', 'sd.seller_id')
            ->selectRaw('sd.seller_id as id, max(dist_s.name) as name, max(dist_s.normalized_name) as normalized, sum('.SalesBasis::net('sales_lines').') as net')
            ->groupBy('sd.seller_id')->orderByRaw('sum('.SalesBasis::net('sales_lines').') desc')->toBase()->get();
        $branches = $base()->join('branches as dist_b', 'dist_b.id', '=', 'sales_lines.branch_id')
            ->selectRaw('sales_lines.branch_id as id, max(dist_b.name) as name, sum('.SalesBasis::net('sales_lines').') as net')
            ->groupBy('sales_lines.branch_id')->orderByRaw('sum('.SalesBasis::net('sales_lines').') desc')->toBase()->get();
        $total = (float) $sellers->sum('net');
        $share = fn ($net) => $total > 0 ? round((float) $net / $total * 100, 2) : null;

        $documents = DB::table('sales_documents')
            ->where('operator_id', $operator->id)
            ->where('issued_on', '>=', $from->toDateString())->where('issued_on', '<', $to->addDay()->toDateString())
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', (array) $companyIds))
            ->when(isset($filters['branch_id']), fn ($q) => $q->where('branch_id', (int) $filters['branch_id']))
            ->get(['issued_on', 'issued_at']);

        $byHour = array_fill(0, 24, 0);
        $byWeekday = array_fill(1, 7, 0);
        $daily = [];
        $timed = 0;
        foreach ($documents as $d) {
            $day = substr((string) $d->issued_on, 0, 10);
            $daily[$day] = ($daily[$day] ?? 0) + 1;
            $byWeekday[CarbonImmutable::parse($day)->dayOfWeekIso]++;
            if ($d->issued_at !== null) {
                $timed++;
                $byHour[(int) CarbonImmutable::parse((string) $d->issued_at)->format('G')]++;
            }
        }
        ksort($daily);

        return [
            'operator' => ['id' => $operator->id, 'name' => $operator->name],
            'sellers' => $sellers->take(15)->map(fn ($s) => ['id' => (int) $s->id, 'name' => $s->name, 'net_total' => round((float) $s->net, 2), 'share_pct' => $share($s->net), 'direct' => $s->normalized === $operator->normalized_name])->values()->all(),
            'sellers_count' => $sellers->count(),
            'branches' => $branches->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name, 'net_total' => round((float) $b->net, 2), 'share_pct' => $share($b->net)])->values()->all(),
            'documents' => $documents->count(),
            'timed_documents' => $timed,
            'by_hour' => $timed > 0 ? $byHour : null,
            'by_weekday' => array_values($byWeekday),
            'daily' => array_map(fn (string $date, int $count) => ['date' => $date, 'documents' => $count], array_keys($daily), array_values($daily)),
        ];
    }
}
