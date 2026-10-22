<?php

declare(strict_types=1);

namespace App\Services;

use App\Imports\Decimal;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Libro de movimientos del crédito. El saldo de una factura es siempre la suma de sus movimientos: no existe forma de
 * modificarlo directamente, y los movimientos no se editan ni se borran (un error se corrige con una anulación enlazada).
 *
 * Reglas: un abono o una nota de crédito no puede dejar el saldo en negativo (no hay sobrepago; un excedente solo puede
 * entrar por un flujo explícito de saldo a favor, que esta clase no ofrece); el movimiento no puede ser anterior a la
 * factura ni futuro; la factura original no se anula.
 */
class CreditLedger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** Saldo actual de una factura, como texto con 4 decimales. */
    public function balance(CreditInvoice $invoice): string
    {
        return Decimal::format($this->units((int) $invoice->id));
    }

    /**
     * Abono, nota de crédito o ajuste (con signo: positivo aumenta el saldo, negativo lo reduce).
     *
     * @param  array{method?: string|null, reference?: string|null, note?: string|null, credit_note_id?: int|null}  $meta
     */
    public function record(CreditInvoice $invoice, string $type, string $amount, CarbonInterface $occurredOn, User $user, array $meta = []): CreditMovement
    {
        if (! in_array($type, [CreditMovement::PAYMENT, CreditMovement::CREDIT_NOTE, CreditMovement::ADJUSTMENT], true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de movimiento no válido.']);
        }
        $units = Decimal::units($amount);
        if ($units === 0) {
            throw ValidationException::withMessages(['amount' => 'El importe debe ser distinto de cero.']);
        }
        if ($type !== CreditMovement::ADJUSTMENT && $units < 0) {
            throw ValidationException::withMessages(['amount' => 'El importe debe ser mayor que cero.']);
        }
        if ($type === CreditMovement::ADJUSTMENT && trim((string) ($meta['note'] ?? '')) === '') {
            throw ValidationException::withMessages(['note' => 'Un ajuste exige una nota que explique el motivo.']);
        }
        $signed = $type === CreditMovement::ADJUSTMENT ? $units : -$units;

        return $this->append($invoice, $type, $signed, $occurredOn, $user, $meta);
    }

    /** Anula un movimiento con otro de signo contrario que lo referencia; el original queda intacto. */
    public function reverse(CreditMovement $movement, User $user, string $note): CreditMovement
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Indica el motivo de la anulación.']);
        }

        return DB::transaction(function () use ($movement, $user, $note) {
            $invoice = $this->lock($movement->credit_invoice_id);
            if (! in_array($movement->type, CreditMovement::REVERSIBLE, true)) {
                throw ValidationException::withMessages(['movement' => 'Este movimiento no se puede anular.']);
            }
            if (CreditMovement::query()->where('reverses_id', $movement->id)->exists()) {
                throw ValidationException::withMessages(['movement' => 'Este movimiento ya fue anulado.']);
            }

            return $this->append($invoice, CreditMovement::REVERSAL, -Decimal::units((string) $movement->amount), now(), $user, ['note' => $note, 'credit_note_id' => $movement->credit_note_id], $movement->id, locked: true);
        });
    }

    /** @param  array{method?: string|null, reference?: string|null, note?: string|null, credit_note_id?: int|null}  $meta */
    private function append(CreditInvoice $invoice, string $type, int $signed, CarbonInterface $occurredOn, User $user, array $meta, ?int $reverses = null, bool $locked = false): CreditMovement
    {
        $work = function (CreditInvoice $locked) use ($type, $signed, $occurredOn, $user, $meta, $reverses) {
            if ($reverses === null) {
                if ($occurredOn->startOfDay()->lt($locked->issued_on->startOfDay())) {
                    throw ValidationException::withMessages(['occurred_on' => 'La fecha no puede ser anterior a la de la factura.']);
                }
                if ($occurredOn->startOfDay()->gt(now()->startOfDay())) {
                    throw ValidationException::withMessages(['occurred_on' => 'La fecha no puede ser futura.']);
                }
            }

            $balance = $this->units((int) $locked->id);
            if ($balance + $signed < 0) {
                throw ValidationException::withMessages(['amount' => 'El importe supera el saldo de la factura ('.Decimal::format($balance).'): no se permite el sobrepago.']);
            }

            $movement = CreditMovement::create([
                'company_id' => $locked->company_id, 'credit_invoice_id' => $locked->id, 'type' => $type, 'amount' => Decimal::format($signed),
                'occurred_on' => $reverses === null ? $occurredOn->toDateString() : now()->toDateString(),
                'method' => $meta['method'] ?? null, 'reference' => $meta['reference'] ?? null, 'note' => $meta['note'] ?? null,
                'user_id' => $user->id, 'reverses_id' => $reverses, 'credit_note_id' => $meta['credit_note_id'] ?? null, 'created_at' => now(),
            ]);
            $this->audit->record('credit.movement_'.$type, $user, auditable: $movement, new: $movement->only(['credit_invoice_id', 'type', 'amount', 'occurred_on', 'method', 'reference', 'note', 'reverses_id', 'credit_note_id']), companyId: $locked->company_id);

            return $movement;
        };

        if ($locked) {
            return $work($invoice);
        }

        return DB::transaction(fn () => $work($this->lock($invoice->id)));
    }

    private function lock(int $invoiceId): CreditInvoice
    {
        // Se bloquea la fila de la factura para que dos movimientos simultáneos no calculen el mismo saldo.
        return CreditInvoice::query()->lockForUpdate()->findOrFail($invoiceId);
    }

    private function units(int $invoiceId): int
    {
        return Decimal::units(number_format((float) DB::table('credit_movements')->where('credit_invoice_id', $invoiceId)->sum('amount'), Decimal::SCALE, '.', ''));
    }
}
