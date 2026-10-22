<?php

declare(strict_types=1);

namespace App\Services;

use App\Imports\Decimal;
use App\Models\CreditInvoice;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Calificación de crédito explicable de un cliente (0 a 100). Es orientativa: no aprueba ni modifica límites de crédito y
 * no es la clasificación ABC. Cada factor se calcula con datos verificables del libro de crédito, las promesas de pago y
 * la actividad de compra, y se muestra con su puntuación, su peso y la razón. Un factor sin datos no puntúa (ni a favor ni
 * en contra): su peso se reparte entre los demás.
 *
 * - Puntualidad de abonos: parte del importe facturado en la ventana que no estaba saldado al cumplirse los primeros
 *   N días de la factura (N = primer tramo de antigüedad); lo no vencido todavía no cuenta como atraso.
 * - Promesas cumplidas: promesas resueltas (cumplidas o incumplidas) en la ventana; las canceladas y pendientes no cuentan.
 * - Antigüedad del saldo: el saldo abierto pesa más cuanto más antiguo (tramo 0 = sin castigo, último tramo = castigo total).
 * - Frecuencia de compra: estado de actividad del cliente (activo, nuevo, recuperado, inactivo, perdido).
 */
class CreditScore
{
    private const FREQUENCY = ['active' => 100, 'recovered' => 80, 'new' => 70, 'inactive' => 40, 'lost' => 0];

    public function __construct(private readonly CreditPortfolio $portfolio, private readonly PaymentPromises $promises, private readonly CustomerActivity $activity) {}

    /**
     * @return array{score: int|null, band: string|null, factors: list<array<string, mixed>>, window_days: int, disclaimer: string}
     */
    public function forCustomer(User $user, int $companyId, string $code, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $window = (int) config('credit.scoring.window_days', 365);
        $weights = (array) config('credit.scoring.weights');

        $factors = [
            $this->punctuality($companyId, $code, $today, $window),
            $this->promises($user, $companyId, $code, $today, $window),
            $this->aging($companyId, $code, $today),
            $this->frequency($companyId, $code, $today),
        ];
        $keys = ['punctuality', 'promises', 'aging', 'frequency'];
        $labels = ['punctuality' => 'Puntualidad de abonos', 'promises' => 'Promesas de pago cumplidas', 'aging' => 'Antigüedad del saldo', 'frequency' => 'Frecuencia de compra'];

        $available = 0;
        foreach ($factors as $i => $f) {
            $weight = (int) ($weights[$keys[$i]] ?? 0);
            $factors[$i] = ['key' => $keys[$i], 'label' => $labels[$keys[$i]], 'weight' => $weight] + $f;
            if ($f['score'] !== null) {
                $available += $weight;
            }
        }

        $total = 0.0;
        foreach ($factors as $i => $f) {
            $effective = $f['score'] !== null && $available > 0 ? round($f['weight'] * 100 / $available, 1) : 0.0;
            $factors[$i]['effective_weight'] = $effective;
            $total += $f['score'] !== null ? $f['score'] * $effective / 100 : 0;
        }

        $score = $available > 0 ? (int) round($total) : null;
        $band = $score === null ? null : ($score >= (int) config('credit.scoring.low_risk_from') ? 'low' : ($score >= (int) config('credit.scoring.medium_risk_from') ? 'medium' : 'high'));

        return [
            'score' => $score, 'band' => $band, 'factors' => $factors, 'window_days' => $window,
            'disclaimer' => 'Calificación orientativa. No aprueba ni modifica límites de crédito y no es la clasificación ABC.',
        ];
    }

    /** @return array{score: int|null, detail: string} */
    private function punctuality(int $companyId, string $code, CarbonImmutable $today, int $window): array
    {
        $limit = CreditAging::limits()[0] ?? 30;
        $invoices = CreditInvoice::query()->where('company_id', $companyId)->where('customer_code', $code)->where('issued_on', '>=', $today->subDays($window)->toDateString())->get(['id', 'issued_on', 'total']);
        if ($invoices->isEmpty()) {
            return ['score' => null, 'detail' => "Sin facturas de crédito en los últimos {$window} días."];
        }

        // Abonos y notas de crédito vigentes (no anulados), por factura.
        $settlements = DB::table('credit_movements as m')->whereIn('m.credit_invoice_id', $invoices->pluck('id'))->whereIn('m.type', ['payment', 'credit_note'])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_movements as r')->whereColumn('r.reverses_id', 'm.id'))
            ->get(['m.credit_invoice_id', 'm.occurred_on', 'm.amount'])->groupBy('credit_invoice_id');

        $billed = 0;
        $late = 0;
        $lateInvoices = 0;
        $due = 0;
        foreach ($invoices as $invoice) {
            $total = Decimal::units((string) $invoice->total);
            $billed += $total;
            $dueOn = CarbonImmutable::parse($invoice->issued_on)->addDays($limit);
            if ($dueOn->gte($today)) {
                continue;   // todavía no cumple los primeros N días
            }
            $due++;
            $settled = 0;
            foreach ($settlements->get($invoice->id, collect()) as $m) {
                if (CarbonImmutable::parse($m->occurred_on)->lte($dueOn)) {
                    $settled += -Decimal::units(number_format((float) $m->amount, Decimal::SCALE, '.', ''));
                }
            }
            $overdue = max(0, $total - $settled);
            $late += $overdue;
            $lateInvoices += $overdue > 0 ? 1 : 0;
        }
        if ($due === 0) {
            return ['score' => null, 'detail' => "Ninguna factura ha cumplido aún {$limit} días."];
        }

        return [
            'score' => (int) round(100 * (1 - $late / max(1, $billed))),
            'detail' => "{$lateInvoices} de {$due} facturas con más de {$limit} días no estaban saldadas a los {$limit} días (".Decimal::format($late).' de '.Decimal::format($billed).' facturado).',
        ];
    }

    /** @return array{score: int|null, detail: string} */
    private function promises(User $user, int $companyId, string $code, CarbonImmutable $today, int $window): array
    {
        $from = $today->subDays($window)->toDateString();
        $rows = array_filter($this->promises->list($user, ['company_id' => $companyId, 'code' => $code, 'status' => 'all'], $today)['rows'], fn (array $r) => $r['made_on'] >= $from);
        $kept = count(array_filter($rows, fn (array $r) => $r['status'] === PaymentPromises::KEPT));
        $broken = count(array_filter($rows, fn (array $r) => $r['status'] === PaymentPromises::BROKEN));
        if ($kept + $broken === 0) {
            return ['score' => null, 'detail' => "Sin promesas de pago resueltas en los últimos {$window} días."];
        }

        return ['score' => (int) round(100 * $kept / ($kept + $broken)), 'detail' => "{$kept} cumplidas y {$broken} incumplidas."];
    }

    /** @return array{score: int|null, detail: string} */
    private function aging(int $companyId, string $code, CarbonImmutable $today): array
    {
        $hasInvoices = CreditInvoice::query()->where('company_id', $companyId)->where('customer_code', $code)->exists();
        if (! $hasInvoices) {
            return ['score' => null, 'detail' => 'El cliente no tiene facturas de crédito.'];
        }
        $aging = $this->portfolio->aging($companyId, $code, $today);
        $customer = $aging['customers'][0] ?? null;
        if ($customer === null) {
            return ['score' => 100, 'detail' => 'Sin saldo por cobrar.'];
        }

        $buckets = $aging['buckets'];
        $total = Decimal::units($customer['total']);
        $penalty = 0.0;
        foreach ($buckets as $i => $bucket) {
            $penalty += Decimal::units($customer['buckets'][$bucket]) * ($i / max(1, count($buckets) - 1));
        }

        return ['score' => (int) round(100 * (1 - $penalty / max(1, $total))), 'detail' => 'Saldo por cobrar '.$customer['total'].'; la factura más antigua tiene '.$customer['oldest_days'].' días.'];
    }

    /** @return array{score: int|null, detail: string} */
    private function frequency(int $companyId, string $code, CarbonImmutable $today): array
    {
        $status = $this->activity->statusesFor($companyId, [$code], $today)[$companyId][$code] ?? 'no_sales';
        $names = ['active' => 'activo', 'recovered' => 'recuperado', 'new' => 'nuevo', 'inactive' => 'inactivo', 'lost' => 'perdido'];

        return isset(self::FREQUENCY[$status])
            ? ['score' => self::FREQUENCY[$status], 'detail' => 'Cliente '.$names[$status].' según su última compra.']
            : ['score' => null, 'detail' => 'Sin compras en las ventas importadas.'];
    }
}
