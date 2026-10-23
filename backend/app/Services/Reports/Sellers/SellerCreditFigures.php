<?php

declare(strict_types=1);

namespace App\Services\Reports\Sellers;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Saldo por cobrar de las facturas de cada vendedor a la fecha. Vencido = factura emitida hace más de `config('reports.overdue_after_days')`
 * días: el sistema no guarda plazos de crédito por cliente.
 */
class SellerCreditFigures
{
    /**
     * @param  list<int>|null  $companyIds
     * @return array<int, array{balance: float, overdue: float, overdue_pct: float|null}>
     */
    public function snapshot(?array $companyIds, CarbonImmutable $today): array
    {
        $cutoff = $today->subDays((int) config('reports.overdue_after_days'))->toDateString();
        $balances = DB::table('credit_movements')->selectRaw('credit_invoice_id, sum(amount) as balance')->groupBy('credit_invoice_id');

        $rows = DB::table('credit_invoices as ci')
            ->joinSub($balances, 'm', 'm.credit_invoice_id', '=', 'ci.id')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('ci.company_id', (array) $companyIds))
            ->where('m.balance', '>', 0)
            ->selectRaw('ci.seller_id as seller_id, sum(m.balance) as balance, sum(case when ci.issued_on < ? then m.balance else 0 end) as overdue', [$cutoff])
            ->groupBy('ci.seller_id')->get();

        $out = [];
        foreach ($rows as $r) {
            $balance = (float) $r->balance;
            $out[(int) $r->seller_id] = [
                'balance' => round($balance, 2),
                'overdue' => round((float) $r->overdue, 2),
                'overdue_pct' => $balance > 0 ? round((float) $r->overdue / $balance * 100, 2) : null,
            ];
        }

        return $out;
    }
}
