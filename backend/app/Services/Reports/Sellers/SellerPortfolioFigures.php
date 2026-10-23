<?php

declare(strict_types=1);

namespace App\Services\Reports\Sellers;

use App\Services\CustomerActivity;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cartera de clientes de cada vendedor A LA FECHA (no depende del periodo del reporte). Un cliente es de quien le facturó la última
 * vez, si fue dentro de la ventana de `config('reports.portfolio_window_days')`; su estado (activo, nuevo, recuperado, inactivo,
 * perdido) usa las mismas reglas y plazos que la pantalla de Clientes.
 */
class SellerPortfolioFigures
{
    public function __construct(private readonly CustomerActivity $activity) {}

    /**
     * @param  list<int>|null  $companyIds
     * @return array<int, array<string, mixed>> vendedor → conteos por estado
     */
    public function snapshot(?array $companyIds, CarbonImmutable $today): array
    {
        $since = $today->subDays((int) config('reports.portfolio_window_days'))->toDateString();

        $documents = DB::table('sales_documents')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('company_id', (array) $companyIds))
            ->where('issued_on', '>=', $since)->where('issued_on', '<=', $today->toDateString())
            ->selectRaw('company_id, customer_code, seller_id, row_number() over (partition by company_id, customer_code order by issued_on desc, id desc) as rn');

        $owners = DB::query()->fromSub($documents, 'd')->where('rn', 1)->get(['company_id', 'customer_code', 'seller_id']);

        $rules = $this->activity->rules();
        $starts = $this->activity->historyStarts();
        $facts = [];
        $counts = [];
        foreach ($owners as $owner) {
            $company = (int) $owner->company_id;
            $facts[$company] ??= $this->activity->facts($company)[$company] ?? [];
            $status = $this->activity->classify($facts[$company][$owner->customer_code] ?? null, $starts[$company] ?? null, $today, $rules);
            $counts[(int) $owner->seller_id][$status] = ($counts[(int) $owner->seller_id][$status] ?? 0) + 1;
        }

        $out = [];
        foreach ($counts as $sellerId => $byStatus) {
            $total = array_sum($byStatus);
            $lost = $byStatus['lost'] ?? 0;
            $out[$sellerId] = [
                'total' => $total, 'active' => $byStatus['active'] ?? 0, 'new' => $byStatus['new'] ?? 0, 'recovered' => $byStatus['recovered'] ?? 0,
                'inactive' => $byStatus['inactive'] ?? 0, 'lost' => $lost, 'lost_pct' => round($lost / $total * 100, 2),
            ];
        }

        return $out;
    }
}
