<?php

declare(strict_types=1);

namespace App\Services;

use App\Imports\Decimal;
use App\Models\CreditInvoice;
use App\Models\CreditNote;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Cartera por cobrar: saldo abierto por cliente repartido en los tramos de antigüedad (contados desde la fecha de la
 * factura), y devoluciones (notas de crédito vigentes) por cliente. Respeta el ámbito del usuario porque parte de
 * {@see CreditInvoice}, que ya filtra por compañía, sucursal y vendedor.
 */
class CreditPortfolio
{
    /**
     * @param  list<int>|null  $sellerIds  limita a las facturas de esos vendedores (null = todas las visibles)
     * @return array{buckets: list<string>, totals: array<string, string>, customers: list<array<string, mixed>>}
     */
    public function aging(?int $companyId = null, ?string $code = null, ?CarbonImmutable $today = null, ?array $sellerIds = null): array
    {
        $today ??= CarbonImmutable::today();
        $buckets = CreditAging::buckets();
        $balances = DB::table('credit_movements')->selectRaw('credit_invoice_id, sum(amount) as balance')->groupBy('credit_invoice_id');

        $rows = CreditInvoice::query()->leftJoinSub($balances, 'm', 'm.credit_invoice_id', '=', 'credit_invoices.id')
            ->with('company:id,name')
            ->select('credit_invoices.id', 'credit_invoices.company_id', 'credit_invoices.customer_code', 'credit_invoices.customer_name', 'credit_invoices.issued_on')
            ->selectRaw('coalesce(m.balance, 0) as balance')
            ->whereRaw('coalesce(m.balance, 0) > 0')
            ->when($companyId !== null, fn ($q) => $q->where('credit_invoices.company_id', $companyId))
            ->when($code !== null, fn ($q) => $q->where('credit_invoices.customer_code', $code))
            ->when($sellerIds !== null, fn ($q) => $q->whereIn('credit_invoices.seller_id', $sellerIds))
            ->orderBy('credit_invoices.id')->get();

        $zero = array_fill_keys($buckets, 0);
        $totals = $zero;
        $customers = [];
        foreach ($rows as $invoice) {
            $age = max(0, (int) CarbonImmutable::parse($invoice->issued_on)->startOfDay()->diffInDays($today, false));
            $bucket = CreditAging::bucket($age);
            $units = Decimal::units(number_format((float) $invoice->getAttribute('balance'), Decimal::SCALE, '.', ''));
            $key = $invoice->company_id.'|'.$invoice->customer_code;
            $customers[$key] ??= [
                'company_id' => $invoice->company_id, 'company' => $invoice->company->name, 'code' => $invoice->customer_code, 'name' => $invoice->customer_name,
                'buckets' => $zero, 'total' => 0, 'invoices' => 0, 'oldest_days' => 0,
            ];
            $customers[$key]['buckets'][$bucket] += $units;
            $customers[$key]['total'] += $units;
            $customers[$key]['invoices']++;
            $customers[$key]['oldest_days'] = max($customers[$key]['oldest_days'], $age);
            $totals[$bucket] += $units;
        }

        $format = fn (array $buckets) => array_map(Decimal::format(...), $buckets);
        $list = array_map(fn (array $c) => [...$c, 'buckets' => $format($c['buckets']), 'total' => Decimal::format($c['total'])], array_values($customers));
        usort($list, fn (array $a, array $b) => [Decimal::units($b['total']), $a['name']] <=> [Decimal::units($a['total']), $b['name']]);

        return ['buckets' => $buckets, 'totals' => $format($totals) + ['total' => Decimal::format(array_sum($totals))], 'customers' => $list];
    }

    /**
     * Devoluciones vigentes (notas de crédito no anuladas) de un cliente.
     *
     * @return array{count: int, subtotal: string, tax_total: string, total: string}
     */
    public function returns(int $companyId, string $code): array
    {
        $notes = CreditNote::query()->where('company_id', $companyId)->where('customer_code', $code)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_movements as m')
                ->join('credit_movements as r', 'r.reverses_id', '=', 'm.id')->whereColumn('m.credit_note_id', 'credit_notes.id'))
            ->get(['subtotal', 'tax_total', 'total']);
        $sum = fn (string $field) => Decimal::format($notes->sum(fn (CreditNote $n) => Decimal::units((string) $n->getAttribute($field))));

        return ['count' => $notes->count(), 'subtotal' => $sum('subtotal'), 'tax_total' => $sum('tax_total'), 'total' => $sum('total')];
    }
}
