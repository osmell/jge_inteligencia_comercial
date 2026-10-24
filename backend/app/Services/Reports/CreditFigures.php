<?php

declare(strict_types=1);

namespace App\Services\Reports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Saldo por cobrar de las facturas de cada vendedor o sucursal a la fecha. Vencido = factura emitida hace más de `config('reports.overdue_after_days')`
 * días: el sistema no guarda plazos de crédito por cliente.
 */
class CreditFigures
{
    /** Columna de la factura por la que se agrupa (lista blanca). */
    private const COLUMNS = ['seller' => 'ci.seller_id', 'branch' => 'ci.branch_id'];

    /**
     * @param  list<int>|null  $companyIds
     * @param  'seller'|'branch'  $dimension
     * @return array<int, array{balance: float, overdue: float, overdue_pct: float|null}>
     */
    public function snapshot(?array $companyIds, CarbonImmutable $today, string $dimension = 'seller'): array
    {
        $column = self::COLUMNS[$dimension];
        $cutoff = $today->subDays((int) config('reports.overdue_after_days'))->toDateString();
        $balances = DB::table('credit_movements')->selectRaw('credit_invoice_id, sum(amount) as balance')->groupBy('credit_invoice_id');

        $rows = DB::table('credit_invoices as ci')
            ->joinSub($balances, 'm', 'm.credit_invoice_id', '=', 'ci.id')
            ->when($companyIds !== null, fn ($q) => $q->whereIn('ci.company_id', (array) $companyIds))
            ->where('m.balance', '>', 0)
            ->selectRaw("{$column} as owner_id, sum(m.balance) as balance, sum(case when ci.issued_on < ? then m.balance else 0 end) as overdue", [$cutoff])
            ->groupByRaw($column)->get();

        $out = [];
        foreach ($rows as $r) {
            $balance = (float) $r->balance;
            $out[(int) $r->owner_id] = [
                'balance' => round($balance, 2),
                'overdue' => round((float) $r->overdue, 2),
                'overdue_pct' => $balance > 0 ? round((float) $r->overdue / $balance * 100, 2) : null,
            ];
        }

        return $out;
    }
}
