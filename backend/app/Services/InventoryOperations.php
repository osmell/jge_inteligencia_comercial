<?php

namespace App\Services;

use App\Imports\Decimal;
use App\Inventory\Quantity;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Movimientos de inventario capturados en pantalla: ajustes, devoluciones de cliente y a proveedor, traslados entre sucursales y
 * sus reversas. Todos pasan por {@see InventoryLedger} (sin existencias negativas, costo promedio, auditoría con quién y cuándo).
 * Los ajustes y devoluciones llevan un motivo de una lista fija ("otro" exige una nota); un traslado es una salida y una entrada
 * enlazadas por la misma referencia, y se revierten juntas; un documento de entrada se revierte completo.
 */
class InventoryOperations
{
    private const TYPES = [
        InventoryMovement::ADJUSTMENT_IN => 'Ajuste de entrada', InventoryMovement::ADJUSTMENT_OUT => 'Ajuste de salida',
        InventoryMovement::RETURN_IN => 'Devolución de cliente', InventoryMovement::SUPPLIER_RETURN => 'Devolución a proveedor',
    ];

    public function __construct(private readonly InventoryLedger $ledger) {}

    /**
     * Ajuste o devolución. En las entradas, el costo unitario (en la unidad capturada) es opcional: sin él se valoran al costo promedio.
     */
    public function record(User $user, int $companyId, int $branchId, Product $product, string $type, string $quantity, ?int $unitId, CarbonInterface $on, string $reason, ?string $note, ?string $unitCost = null): InventoryMovement
    {
        if (! isset(self::TYPES[$type])) {
            throw ValidationException::withMessages(['type' => 'Tipo de movimiento no válido.']);
        }
        $reasons = in_array($type, [InventoryMovement::ADJUSTMENT_IN, InventoryMovement::ADJUSTMENT_OUT], true) ? InventoryMovement::ADJUSTMENT_REASONS : InventoryMovement::RETURN_REASONS;
        if (! in_array($reason, $reasons, true)) {
            throw ValidationException::withMessages(['reason' => 'Elige un motivo de la lista.']);
        }
        if ($reason === 'other' && trim((string) $note) === '') {
            throw ValidationException::withMessages(['note' => 'Con el motivo «Otro» escribe una nota que explique.']);
        }

        return $this->ledger->record(
            $companyId, $branchId, $product, $type, $quantity, $unitId, $on, $user, value: $this->value($type, $quantity, $unitCost),
            reference: self::TYPES[$type], note: $note !== null && trim($note) !== '' ? trim($note) : null, reason: $reason,
        );
    }

    /**
     * Traslado entre sucursales de una compañía: sale de una y entra en la otra al mismo valor, en una sola operación.
     *
     * @return array{out: InventoryMovement, in: InventoryMovement}
     */
    public function transfer(User $user, int $companyId, int $fromBranchId, int $toBranchId, Product $product, string $quantity, ?int $unitId, CarbonInterface $on, ?string $note): array
    {
        if ($fromBranchId === $toBranchId) {
            throw ValidationException::withMessages(['to_branch_id' => 'La sucursal de destino debe ser distinta a la de origen.']);
        }

        return DB::transaction(function () use ($user, $companyId, $fromBranchId, $toBranchId, $product, $quantity, $unitId, $on, $note) {
            $reference = 'TR-'.Str::upper(Str::random(8));
            $note = $note !== null && trim($note) !== '' ? trim($note) : null;
            $out = $this->ledger->record($companyId, $fromBranchId, $product, InventoryMovement::TRANSFER_OUT, $quantity, $unitId, $on, $user, reference: $reference, note: $note, sourceKey: "transfer:{$reference}:out");
            $in = $this->ledger->record(
                $companyId, $toBranchId, $product, InventoryMovement::TRANSFER_IN, $quantity, $unitId, $on, $user, value: ltrim((string) $out->value, '-'),
                reference: $reference, note: $note, sourceKey: "transfer:{$reference}:in",
            );

            return ['out' => $out, 'in' => $in];
        });
    }

    /**
     * Revierte un movimiento capturado a mano. Un traslado se revierte con su pareja; un documento de entrada, con todas sus líneas
     * (y el mismo documento, ya corregido, se puede volver a cargar). Las ventas y el inventario inicial no se revierten aquí.
     */
    public function reverse(InventoryMovement $movement, User $user, string $note): void
    {
        if (! in_array($movement->type, InventoryMovement::REVERSIBLE, true)) {
            throw ValidationException::withMessages(['movement' => 'Este movimiento no se puede revertir desde aquí: las ventas siguen a la factura y el inventario inicial se corrige con un ajuste.']);
        }
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Indica el motivo de la reversa.']);
        }

        DB::transaction(function () use ($movement, $user, $note) {
            if (in_array($movement->type, [InventoryMovement::TRANSFER_IN, InventoryMovement::TRANSFER_OUT], true)) {
                $pair = InventoryMovement::query()->where('company_id', $movement->company_id)->where('reference', $movement->reference)
                    ->whereIn('type', [InventoryMovement::TRANSFER_IN, InventoryMovement::TRANSFER_OUT])->get();
                // Primero se revierte la entrada (necesita existencias en el destino) y después la salida.
                foreach ($pair->sortBy(fn (InventoryMovement $m) => $m->type === InventoryMovement::TRANSFER_IN ? 0 : 1) as $leg) {
                    $this->ledger->reverse($leg, $user, $note);
                }

                return;
            }
            if ($movement->type === InventoryMovement::ENTRY && $movement->receipt_id !== null) {
                $receipt = InventoryReceipt::query()->lockForUpdate()->findOrFail($movement->receipt_id);
                if ($receipt->reversed_at !== null) {
                    throw ValidationException::withMessages(['movement' => 'Este documento de entrada ya fue revertido.']);
                }
                foreach (InventoryMovement::query()->where('receipt_id', $receipt->id)->orderBy('id')->get() as $line) {
                    $this->ledger->reverse($line, $user, $note);
                }
                $receipt->forceFill(['reversed_at' => now(), 'reversed_by' => $user->id, 'active_key' => null])->save();

                return;
            }

            $this->ledger->reverse($movement, $user, $note);
        });
    }

    private function value(string $type, string $quantity, ?string $unitCost): ?string
    {
        if ($unitCost === null || $unitCost === '') {
            return null;
        }
        if (! in_array($type, InventoryMovement::INFLOWS, true)) {
            throw ValidationException::withMessages(['unit_cost' => 'El costo solo aplica a las entradas; las salidas se valoran al costo promedio.']);
        }
        if (! preg_match('/^\d{1,6}(\.\d{1,4})?$/', $unitCost)) {
            throw ValidationException::withMessages(['unit_cost' => 'El costo debe ser un importe no negativo con hasta 4 decimales.']);
        }
        try {
            return Decimal::format(Quantity::extend(Quantity::units($quantity), Decimal::units($unitCost)));
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad no es válida.']);
        }
    }
}
