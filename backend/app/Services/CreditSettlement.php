<?php

declare(strict_types=1);

namespace App\Services;

use App\Imports\Decimal;
use App\Models\CreditInvoice;
use App\Models\CreditMovement;
use App\Models\CreditNote;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Registro en pantalla de abonos y notas de crédito de un cliente, repartidos entre una o varias de sus facturas.
 * Todo o nada: si una factura no admite su parte (sobrepago, fecha inválida) no se registra nada. Las reglas de cada
 * movimiento las aplica {@see CreditLedger}; aquí se valida que las facturas sean del cliente y que los importes cuadren.
 */
class CreditSettlement
{
    public function __construct(private readonly CreditLedger $ledger, private readonly AuditLogger $audit) {}

    /**
     * @param  list<array{invoice_id: int, amount: string}>  $allocations
     * @param  array{method?: string|null, reference?: string|null, note?: string|null}  $meta
     * @return list<CreditMovement>
     */
    public function payment(int $companyId, string $customerCode, array $allocations, CarbonInterface $on, User $user, array $meta): array
    {
        return DB::transaction(function () use ($companyId, $customerCode, $allocations, $on, $user, $meta) {
            $invoices = $this->invoices($companyId, $customerCode, $allocations);

            return $this->apply($invoices, $allocations, CreditMovement::PAYMENT, $on, $user, $meta);
        });
    }

    /**
     * @param  array<string, mixed>  $document  series, number, subtotal, tax_total, total y observations (opcional)
     * @param  list<array{invoice_id: int, amount: string}>  $allocations
     */
    public function creditNote(int $companyId, string $customerCode, array $document, array $allocations, CarbonInterface $on, User $user): CreditNote
    {
        $units = Decimal::units($document['total']);
        if (Decimal::units($document['subtotal']) + Decimal::units($document['tax_total']) !== $units) {
            throw ValidationException::withMessages(['total' => 'Subtotal + impuesto debe ser igual al total de la nota de crédito.']);
        }
        if ($units <= 0) {
            throw ValidationException::withMessages(['total' => 'El total de la nota de crédito debe ser mayor que cero.']);
        }

        return DB::transaction(function () use ($companyId, $customerCode, $document, $allocations, $on, $user, $units) {
            $invoices = $this->invoices($companyId, $customerCode, $allocations);
            $applied = array_sum(array_map(fn (array $a) => Decimal::units($a['amount']), $allocations));
            if ($applied !== $units) {
                throw ValidationException::withMessages(['allocations' => 'Lo aplicado a las facturas ('.Decimal::format($applied).') debe ser igual al total de la nota ('.Decimal::format($units).'): un excedente no se acepta como saldo.']);
            }

            try {
                $note = CreditNote::create([
                    'company_id' => $companyId, 'series' => $document['series'], 'number' => $document['number'], 'issued_on' => $on->toDateString(),
                    'customer_code' => $customerCode, 'customer_name' => array_values($invoices)[0]->customer_name,
                    'subtotal' => $document['subtotal'], 'tax_total' => $document['tax_total'], 'total' => $document['total'],
                    'observations' => $document['observations'] ?? null, 'user_id' => $user->id, 'created_at' => now(),
                ]);
            } catch (UniqueConstraintViolationException) {
                throw ValidationException::withMessages(['number' => 'Ya existe una nota de crédito con esa serie y documento.']);
            }

            $this->apply($invoices, $allocations, CreditMovement::CREDIT_NOTE, $on, $user, [
                'reference' => "{$note->series}-{$note->number}", 'note' => $note->observations, 'credit_note_id' => $note->id,
            ]);
            $this->audit->record('credit.note_created', $user, auditable: $note, new: $note->only(['series', 'number', 'issued_on', 'customer_code', 'subtotal', 'tax_total', 'total', 'observations']), companyId: $companyId);

            return $note;
        });
    }

    /** Anula un abono (un solo movimiento) o una nota de crédito completa (todos sus movimientos). */
    public function reverse(CreditMovement $movement, User $user, string $note): void
    {
        DB::transaction(function () use ($movement, $user, $note) {
            if ($movement->credit_note_id === null) {
                $this->ledger->reverse($movement, $user, $note);

                return;
            }
            $all = CreditMovement::query()->where('credit_note_id', $movement->credit_note_id)->where('type', CreditMovement::CREDIT_NOTE)->orderBy('id')->get();
            if ($all->contains(fn (CreditMovement $m) => CreditMovement::query()->where('reverses_id', $m->id)->exists())) {
                throw ValidationException::withMessages(['movement' => 'Esta nota de crédito ya fue anulada.']);
            }
            foreach ($all as $m) {
                $this->ledger->reverse($m, $user, $note);
            }
        });
    }

    /**
     * @param  list<array{invoice_id: int, amount: string}>  $allocations
     * @return array<int, CreditInvoice> facturas por id, en el orden de la captura
     */
    private function invoices(int $companyId, string $customerCode, array $allocations): array
    {
        if ($allocations === []) {
            throw ValidationException::withMessages(['allocations' => 'Indica a qué facturas se aplica.']);
        }
        $ids = array_column($allocations, 'invoice_id');
        if (count(array_unique($ids)) !== count($ids)) {
            throw ValidationException::withMessages(['allocations' => 'Una factura solo puede aparecer una vez.']);
        }
        $found = CreditInvoice::query()->where('company_id', $companyId)->where('customer_code', $customerCode)->whereIn('id', $ids)->get()->keyBy('id');
        $result = [];
        foreach ($allocations as $i => $a) {
            $invoice = $found->get($a['invoice_id']);
            if (! $invoice instanceof CreditInvoice) {
                throw ValidationException::withMessages(["allocations.$i.invoice_id" => 'La factura no existe o no pertenece a este cliente.']);
            }
            $result[$invoice->id] = $invoice;
        }

        return $result;
    }

    /**
     * @param  array<int, CreditInvoice>  $invoices
     * @param  list<array{invoice_id: int, amount: string}>  $allocations
     * @param  array<string, mixed>  $meta
     * @return list<CreditMovement>
     */
    private function apply(array $invoices, array $allocations, string $type, CarbonInterface $on, User $user, array $meta): array
    {
        $movements = [];
        foreach ($allocations as $i => $a) {
            try {
                $movements[] = $this->ledger->record($invoices[$a['invoice_id']], $type, $a['amount'], $on, $user, $meta);
            } catch (ValidationException $e) {
                // El error de una parte se muestra en su línea de la captura.
                throw ValidationException::withMessages(collect($e->errors())->mapWithKeys(fn ($m, $k) => [$k === 'amount' ? "allocations.$i.amount" : $k => $m])->all());
            }
        }

        return $movements;
    }
}
