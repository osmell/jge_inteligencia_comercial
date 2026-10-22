<?php

declare(strict_types=1);

namespace App\Services\Customers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Clasificación ABC (Pareto) por venta neta de los últimos 12 meses: A hasta el 80 % acumulado, B hasta el 95 %, C el resto. */
class AbcClassification
{
    public const ABC_A_PCT = 80;

    public const ABC_B_PCT = 95;

    /**
     * Clase ABC de cada cliente de la compañía (código → A|B|C). Se calcula sobre toda la compañía, sin importar las
     * sucursales del usuario, para que la letra no cambie según quién consulte; solo se expone la letra. Sin compras
     * (o venta neta ≤ 0) en los últimos 12 meses no tiene clase.
     *
     * @return array<string, string>
     */
    public function classes(int $companyId, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $totals = DB::table('sales_documents as d')
            ->join('sales_lines as sl', 'sl.sales_document_id', '=', 'd.id')
            ->where('d.company_id', $companyId)
            ->where('d.issued_on', '>=', $today->subYear()->addDay()->toDateString())
            ->where('d.issued_on', '<', $today->addDay()->toDateString())
            ->groupBy('d.customer_code')
            ->selectRaw('d.customer_code as code, sum(sl.net_total) as net_total')
            ->havingRaw('sum(sl.net_total) > 0')
            ->orderByRaw('sum(sl.net_total) desc, d.customer_code asc')
            ->pluck('net_total', 'code');

        $grand = (float) $totals->sum();
        $classes = [];
        $before = 0.0;
        foreach ($totals as $code => $net) {
            // Se clasifica por lo acumulado ANTES del cliente: el primero siempre es A.
            $share = $grand > 0 ? $before / $grand * 100 : 0;
            $classes[(string) $code] = $share < self::ABC_A_PCT ? 'A' : ($share < self::ABC_B_PCT ? 'B' : 'C');
            $before += (float) $net;
        }

        return $classes;
    }
}
