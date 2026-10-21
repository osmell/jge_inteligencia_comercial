<?php

namespace App\Services;

use App\Imports\Decimal;
use App\Inventory\Quantity;
use App\Models\Branch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use OverflowException;

/**
 * Libro de movimientos de inventario. Es el único que escribe movimientos y saldos, siempre en la misma transacción y con
 * el producto bloqueado, de modo que el saldo (cantidad y valor) es exactamente la suma de sus movimientos.
 *
 * Reglas: todo se lleva en la unidad base del producto (se convierte con la equivalencia del producto, sin asumir
 * contenidos universales); las existencias nunca quedan en negativo (una salida mayor al saldo se rechaza); las entradas
 * llevan su valor y las salidas se valoran a costo promedio ponderado (valor ÷ cantidad del saldo); los movimientos no
 * se editan ni se borran (una reversa enlazada los corrige); con una clave de origen, repetir el mismo movimiento no
 * lo duplica; la fecha no puede ser futura.
 */
class InventoryLedger
{
    public function __construct(private readonly AuditLogger $audit) {}

    /**
     * Registra un movimiento (o devuelve el existente si la clave de origen ya se registró; ver wasRecentlyCreated).
     *
     * @param  string  $quantity  cantidad positiva en la unidad indicada ($unitId null = unidad base)
     * @param  string|null  $value  valor total de la entrada (obligatorio en inicial y entradas); en otras entradas se valora al promedio
     */
    public function record(
        int $companyId,
        int $branchId,
        Product $product,
        string $type,
        string $quantity,
        ?int $unitId,
        CarbonInterface $occurredOn,
        ?User $user = null,
        ?string $value = null,
        ?string $reference = null,
        ?string $note = null,
        ?string $sourceKey = null,
        ?int $batchId = null,
        bool $audit = true,
        ?int $receiptId = null,
        ?string $reason = null,
    ): InventoryMovement {
        if (! in_array($type, [...InventoryMovement::INFLOWS, ...InventoryMovement::OUTFLOWS], true)) {
            throw ValidationException::withMessages(['type' => 'Tipo de movimiento no válido.']);
        }

        return DB::transaction(function () use ($companyId, $branchId, $product, $type, $quantity, $unitId, $occurredOn, $user, $value, $reference, $note, $sourceKey, $batchId, $audit, $receiptId, $reason) {
            $locked = Product::query()->lockForUpdate()->find($product->id) ?? throw ValidationException::withMessages(['product' => 'El producto no existe.']);
            if ($locked->company_id !== $companyId) {
                throw ValidationException::withMessages(['product' => 'El producto no pertenece a la compañía.']);
            }
            if (! Branch::query()->whereKey($branchId)->where('company_id', $companyId)->exists()) {
                throw ValidationException::withMessages(['branch' => 'La sucursal no pertenece a la compañía.']);
            }
            if ($sourceKey !== null && ($existing = InventoryMovement::query()->where('company_id', $companyId)->where('source_key', $sourceKey)->first()) !== null) {
                return $existing;
            }
            if ($occurredOn->startOfDay()->gt(now()->startOfDay())) {
                throw ValidationException::withMessages(['occurred_on' => 'La fecha no puede ser futura.']);
            }

            [$entered, $base] = $this->toBase($locked, $quantity, $unitId);
            $inflow = in_array($type, InventoryMovement::INFLOWS, true);
            [$balanceQty, $balanceValue] = $this->balance($companyId, $branchId, $locked->id);

            if ($inflow) {
                $valueUnits = $this->inflowValue($type, $value, $base, $balanceQty, $balanceValue);
                $signedQty = $base;
                $signedValue = $valueUnits;
            } else {
                if ($base > $balanceQty) {
                    throw ValidationException::withMessages(['quantity' => 'Existencia insuficiente: hay '.Quantity::display($balanceQty).' en la unidad base y se pidieron '.Quantity::display($base).'. No se permiten existencias negativas.']);
                }
                $signedQty = -$base;
                $signedValue = -$this->outflowValue($base, $balanceQty, $balanceValue);
            }

            return $this->append($companyId, $branchId, $locked, $type, $signedQty, $signedValue, $occurredOn->toDateString(), $unitId === $locked->base_unit_id ? null : $unitId, $entered, $reference, $note, $user, null, $sourceKey, $batchId, [$balanceQty, $balanceValue], $audit, $receiptId, $reason);
        });
    }

    /** Corrige un movimiento con otro de signo contrario enlazado; el original queda intacto y no se revierte dos veces. */
    public function reverse(InventoryMovement $movement, User $user, string $note): InventoryMovement
    {
        if (trim($note) === '') {
            throw ValidationException::withMessages(['note' => 'Indica el motivo de la reversa.']);
        }

        return DB::transaction(function () use ($movement, $user, $note) {
            $product = Product::query()->lockForUpdate()->findOrFail($movement->product_id);
            if ($movement->type === InventoryMovement::REVERSAL) {
                throw ValidationException::withMessages(['movement' => 'Una reversa no se puede revertir.']);
            }
            if (InventoryMovement::query()->where('reverses_id', $movement->id)->exists()) {
                throw ValidationException::withMessages(['movement' => 'Este movimiento ya fue revertido.']);
            }

            $qty = -Quantity::units((string) $movement->quantity);
            $value = -Decimal::units((string) $movement->value);
            [$balanceQty, $balanceValue] = $this->balance($movement->company_id, $movement->branch_id, $movement->product_id);
            if ($balanceQty + $qty < 0) {
                throw ValidationException::withMessages(['movement' => 'No se puede revertir: dejaría las existencias en negativo ('.Quantity::display($balanceQty).' disponibles).']);
            }

            return $this->append(
                $movement->company_id, $movement->branch_id, $product, InventoryMovement::REVERSAL, $qty, $value, now()->toDateString(), $movement->unit_id,
                Quantity::units((string) $movement->entered_quantity), null, $note, $user, $movement->id, null, null, [$balanceQty, $balanceValue], true,
            );
        });
    }

    /** @return array{0: int, 1: int} [cantidad escalada a 8 decimales, valor escalado a 4 decimales] del saldo actual */
    public function balance(int $companyId, int $branchId, int $productId): array
    {
        $row = DB::table('inventory_balances')->where(['company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $productId])->first();

        return $row === null ? [0, 0] : [Quantity::units((string) $row->quantity), Decimal::units((string) $row->value)];
    }

    /**
     * Cantidad en unidad base: [cantidad tal como se capturó, cantidad en unidad base], ambas escaladas.
     *
     * @return array{0: int, 1: int}
     */
    private function toBase(Product $product, string $quantity, ?int $unitId): array
    {
        if ($product->base_unit_id === null) {
            throw ValidationException::withMessages(['product' => "El producto {$product->code} no tiene unidad base: defínela en Inventario → Unidades."]);
        }
        try {
            $entered = Quantity::units($quantity);
            if ($entered <= 0 || $entered > Quantity::MAX) {
                throw new InvalidArgumentException;
            }
        } catch (InvalidArgumentException) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad debe ser un número mayor que cero y no pasar de 10.000.000.']);
        }
        if ($unitId === null || $unitId === $product->base_unit_id) {
            return [$entered, $entered];
        }

        $equivalence = ProductUnit::query()->where('product_id', $product->id)->where('unit_id', $unitId)->first()
            ?? throw ValidationException::withMessages(['unit' => "El producto {$product->code} no tiene equivalencia para esa unidad."]);
        try {
            $base = Quantity::convert($entered, $equivalence->factor_num, $equivalence->factor_den);
        } catch (OverflowException) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad convertida es demasiado grande.']);
        }
        if ($base <= 0 || $base > Quantity::MAX) {
            throw ValidationException::withMessages(['quantity' => 'La cantidad en unidad base debe ser mayor que cero y no pasar de 10.000.000.']);
        }

        return [$entered, $base];
    }

    private function inflowValue(string $type, ?string $value, int $baseQty, int $balanceQty, int $balanceValue): int
    {
        if ($value !== null && $value !== '') {
            if (! preg_match('/^\d{1,14}(\.\d{1,4})?$/', $value)) {
                throw ValidationException::withMessages(['value' => 'El valor debe ser un importe no negativo con hasta 4 decimales.']);
            }

            return Decimal::units($value);
        }
        if (in_array($type, [InventoryMovement::OPENING, InventoryMovement::ENTRY], true)) {
            throw ValidationException::withMessages(['value' => 'Indica el costo total de la entrada.']);
        }
        if ($balanceQty <= 0) {
            throw ValidationException::withMessages(['value' => 'No hay existencias para valorar al costo promedio: indica el valor.']);
        }

        return $this->average($baseQty, $balanceQty, $balanceValue);
    }

    private function outflowValue(int $baseQty, int $balanceQty, int $balanceValue): int
    {
        return $baseQty === $balanceQty ? $balanceValue : $this->average($baseQty, $balanceQty, $balanceValue);
    }

    /** Valor de una cantidad al costo promedio del saldo, redondeado a 4 decimales (salida total = todo el valor, sin residuos). */
    private function average(int $qty, int $balanceQty, int $balanceValue): int
    {
        return (int) round($balanceValue * ($qty / $balanceQty));
    }

    /** @param  array{0: int, 1: int}  $balance */
    private function append(int $companyId, int $branchId, Product $product, string $type, int $qty, int $value, string $on, ?int $unitId, int $entered, ?string $reference, ?string $note, ?User $user, ?int $reverses, ?string $sourceKey, ?int $batchId, array $balance, bool $audit, ?int $receiptId = null, ?string $reason = null): InventoryMovement
    {
        $movement = InventoryMovement::create([
            'company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $product->id, 'type' => $type, 'quantity' => Quantity::format($qty), 'value' => Decimal::format($value),
            'occurred_on' => $on, 'unit_id' => $unitId, 'entered_quantity' => Quantity::format($entered), 'reference' => $reference, 'note' => $note,
            'user_id' => $user?->id, 'reverses_id' => $reverses, 'source_key' => $sourceKey, 'import_batch_id' => $batchId, 'receipt_id' => $receiptId, 'reason' => $reason, 'created_at' => now(),
        ]);

        $key = ['company_id' => $companyId, 'branch_id' => $branchId, 'product_id' => $product->id];
        $newQty = Quantity::format($balance[0] + $qty);
        $newValue = Decimal::format($balance[1] + $value);
        if (DB::table('inventory_balances')->where($key)->exists()) {
            DB::table('inventory_balances')->where($key)->update(['quantity' => $newQty, 'value' => $newValue, 'updated_at' => now()]);
        } else {
            DB::table('inventory_balances')->insert($key + ['quantity' => $newQty, 'value' => $newValue, 'updated_at' => now()]);
        }

        if ($audit && $user !== null) {
            $this->audit->record('inventory.movement_'.$type, $user, auditable: $movement, new: $movement->only(['branch_id', 'product_id', 'type', 'reason', 'quantity', 'value', 'occurred_on', 'reference', 'note', 'reverses_id']), companyId: $companyId);
        }

        return $movement;
    }
}
