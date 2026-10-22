<?php

declare(strict_types=1);

namespace App\Services;

use App\Imports\Decimal;
use App\Models\CreditInvoice;
use App\Models\PaymentPromise;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Promesas de pago. Una promesa se cumple cuando los abonos vigentes del cliente (no anulados) registrados desde el día
 * de la promesa hasta la fecha prometida suman al menos el monto prometido. Pasada la fecha sin cumplirse queda como
 * incumplida (sin consecuencias: solo se muestra). Se puede cancelar con motivo.
 */
class PaymentPromises
{
    public const OPEN = 'open';

    public const KEPT = 'kept';

    public const BROKEN = 'broken';

    public const CANCELLED = 'cancelled';

    public const MAX_DAYS_AHEAD = 90;

    public function __construct(private readonly AuditLogger $audit, private readonly CreditPortfolio $portfolio) {}

    public function create(User $user, int $companyId, string $code, CarbonImmutable $promisedOn, string $amount, ?string $note): PaymentPromise
    {
        $today = CarbonImmutable::today();
        $units = Decimal::units($amount);
        if ($units <= 0) {
            throw ValidationException::withMessages(['amount' => 'El monto debe ser mayor que cero.']);
        }
        if ($promisedOn->lt($today) || $promisedOn->gt($today->addDays(self::MAX_DAYS_AHEAD))) {
            throw ValidationException::withMessages(['promised_on' => 'La fecha prometida debe ser desde hoy y dentro de los próximos '.self::MAX_DAYS_AHEAD.' días.']);
        }

        $portfolio = $this->portfolio->aging($companyId, $code);
        $customer = $portfolio['customers'][0] ?? null;
        if ($customer === null) {
            throw ValidationException::withMessages(['code' => 'El cliente no tiene saldo por cobrar.']);
        }
        if ($units > Decimal::units($customer['total'])) {
            throw ValidationException::withMessages(['amount' => 'El monto no puede superar el saldo por cobrar del cliente ('.$customer['total'].').']);
        }

        $promise = PaymentPromise::create([
            'company_id' => $companyId, 'customer_code' => $code, 'customer_name' => $customer['name'], 'made_on' => $today->toDateString(),
            'promised_on' => $promisedOn->toDateString(), 'amount' => Decimal::format($units), 'note' => $note, 'user_id' => $user->id,
        ]);
        $this->audit->record('credit.promise_created', $user, auditable: $promise, new: $promise->only(['customer_code', 'made_on', 'promised_on', 'amount', 'note']), companyId: $companyId);

        return $promise;
    }

    public function cancel(PaymentPromise $promise, User $user, string $reason): void
    {
        if ($promise->cancelled_at !== null) {
            throw ValidationException::withMessages(['promise' => 'La promesa ya está cancelada.']);
        }
        $promise->forceFill(['cancelled_at' => now(), 'cancelled_by' => $user->id, 'cancel_reason' => $reason])->save();
        $this->audit->record('credit.promise_cancelled', $user, auditable: $promise, new: ['cancel_reason' => $reason], companyId: $promise->company_id);
    }

    /**
     * Promesas con su estado derivado. Un vendedor solo ve las de clientes con facturas suyas (alcance de CreditInvoice).
     *
     * @param  array{company_id?: int|null, code?: string|null, status?: string|null, horizon_days?: int|null, mine?: bool}  $opts
     * @return array{rows: list<array<string, mixed>>, counts: array<string, int>}
     */
    public function list(?User $user, array $opts, ?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $query = PaymentPromise::query()->with('user:id,name')
            ->whereExists(CreditInvoice::query()->selectRaw('1')->whereColumn('credit_invoices.company_id', 'payment_promises.company_id')->whereColumn('credit_invoices.customer_code', 'payment_promises.customer_code')->toBase())
            ->when(! empty($opts['company_id']), fn (Builder $q) => $q->where('company_id', $opts['company_id']))
            ->when(! empty($opts['code']), fn (Builder $q) => $q->where('customer_code', $opts['code']))
            ->when(! empty($opts['mine']) && $user !== null, fn (Builder $q) => $q->where('user_id', $user?->id))
            ->orderBy('promised_on')->orderBy('id');

        $rows = [];
        $counts = [self::OPEN => 0, self::KEPT => 0, self::BROKEN => 0, self::CANCELLED => 0];
        foreach ($query->limit(1000)->get() as $promise) {
            $paid = $this->paid($promise);
            $status = $this->status($promise, $paid, $today);
            $counts[$status]++;
            $rows[] = [
                'id' => $promise->id, 'company_id' => $promise->company_id, 'customer' => ['code' => $promise->customer_code, 'name' => $promise->customer_name],
                'made_on' => $promise->made_on->toDateString(), 'promised_on' => $promise->promised_on->toDateString(), 'amount' => $this->money($promise->amount),
                'paid' => Decimal::format(min($paid, Decimal::units((string) $promise->amount))), 'status' => $status, 'note' => $promise->note,
                'days_overdue' => $status === self::BROKEN ? (int) $promise->promised_on->startOfDay()->diffInDays($today) : 0,
                'user' => $promise->user?->name, 'cancel_reason' => $promise->cancel_reason,
            ];
        }

        $status = $opts['status'] ?? 'pending';
        $horizon = isset($opts['horizon_days']) ? (int) $opts['horizon_days'] : null;
        $rows = array_values(array_filter($rows, function (array $r) use ($status, $horizon, $today) {
            if ($status === 'pending') {
                // Lo que requiere atención: abiertas dentro del plazo e incumplidas de los últimos 30 días.
                return ($r['status'] === self::OPEN && ($horizon === null || $r['promised_on'] <= $today->addDays($horizon)->toDateString()))
                    || ($r['status'] === self::BROKEN && $r['days_overdue'] <= 30);
            }

            return $status === 'all' || $r['status'] === $status;
        }));

        return ['rows' => array_slice($rows, 0, 200), 'counts' => $counts];
    }

    /** @return string estado derivado de la promesa */
    private function status(PaymentPromise $p, int $paid, CarbonImmutable $today): string
    {
        return match (true) {
            $p->cancelled_at !== null => self::CANCELLED,
            $paid >= Decimal::units((string) $p->amount) => self::KEPT,
            $p->promised_on->startOfDay()->lt($today) => self::BROKEN,
            default => self::OPEN,
        };
    }

    /** Abonos vigentes (no anulados) del cliente entre el día de la promesa y la fecha prometida, en unidades. */
    private function paid(PaymentPromise $p): int
    {
        $sum = DB::table('credit_movements as m')->join('credit_invoices as i', 'i.id', '=', 'm.credit_invoice_id')
            ->where('i.company_id', $p->company_id)->where('i.customer_code', $p->customer_code)->where('m.type', 'payment')
            ->whereBetween('m.occurred_on', [$p->made_on->toDateString(), $p->promised_on->toDateString()])
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('credit_movements as r')->whereColumn('r.reverses_id', 'm.id'))
            ->sum('m.amount');

        return -Decimal::units(number_format((float) $sum, Decimal::SCALE, '.', ''));
    }

    private function money(mixed $value): string
    {
        return Decimal::format(Decimal::units((string) $value));
    }
}
