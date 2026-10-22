<?php

declare(strict_types=1);

namespace App\Services;

use App\Inventory\Quantity;
use App\Models\InventoryMovement;
use App\Models\InventoryShortfall;
use App\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Salidas de inventario a partir de las líneas de venta (las unidades vendidas ya vienen en la unidad base del producto).
 *
 * Reglas: solo se descuentan las ventas de sucursales con inventario inicial y posteriores a su fecha de corte; cada línea se
 * procesa una sola vez (clave de origen «sale:<id de la línea>»), de modo que reimportar o volver a sincronizar no duplica
 * salidas; las existencias nunca quedan en negativo: si no alcanzan, se descuenta lo disponible y lo que falta queda como
 * «faltante por conciliar» (la venta ya ocurrió y no se toca); un producto que no está en el catálogo o no tiene unidad base
 * también queda como faltante hasta que se corrija.
 */
class SalesInventorySync
{
    public function __construct(private readonly InventoryLedger $ledger, private readonly AuditLogger $audit) {}

    /**
     * Procesa las líneas de venta pendientes, de la más antigua a la más reciente.
     *
     * @return array{branches: int, lines: int, movements: int, shortfalls: int}
     */
    public function run(?int $companyId = null, ?int $branchId = null): array
    {
        $cutoffs = DB::table('inventory_movements')->where('type', InventoryMovement::OPENING)
            ->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->when($branchId !== null, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('company_id', 'branch_id')->selectRaw('company_id, branch_id, min(occurred_on) as cutoff')->get();

        $result = ['branches' => $cutoffs->count(), 'lines' => 0, 'movements' => 0, 'shortfalls' => 0];
        $products = [];

        foreach ($cutoffs as $c) {
            $lines = DB::table('sales_lines as l')->join('sales_documents as d', 'd.id', '=', 'l.sales_document_id')
                ->where('l.company_id', $c->company_id)->where('l.branch_id', $c->branch_id)->where('l.issued_on', '>', CarbonImmutable::parse($c->cutoff)->toDateString())
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_movements as m')->whereColumn('m.company_id', 'l.company_id')->whereRaw("m.source_key = 'sale:' || l.id"))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('inventory_shortfalls as s')->whereColumn('s.sales_line_id', 'l.id'))
                ->orderBy('l.issued_on')->orderBy('l.id')
                ->get(['l.id', 'l.company_id', 'l.branch_id', 'l.issued_on', 'l.product_code', 'l.units', 'd.series', 'd.number']);

            foreach ($lines as $line) {
                $result['lines']++;
                $product = $products["{$line->company_id}|{$line->product_code}"] ??= Product::withoutGlobalScopes()->where(['company_id' => $line->company_id, 'code' => $line->product_code])->first() ?? false;
                $this->line($line, $product ?: null, $result);
            }
        }

        return $result;
    }

    /**
     * Intenta aplicar ahora lo que faltó de una venta (porque ya entró mercancía o se corrigió el producto). Descuenta lo que
     * haya disponible y deja el resto pendiente; el movimiento queda con la fecha en que se concilia.
     *
     * @return array{applied: string, missing: string, resolved: bool}
     */
    public function reconcile(InventoryShortfall $shortfall, User $user): array
    {
        return DB::transaction(function () use ($shortfall, $user) {
            $locked = InventoryShortfall::query()->lockForUpdate()->findOrFail($shortfall->id);
            if ($locked->resolved_at !== null) {
                return ['applied' => Quantity::display(0), 'missing' => Quantity::display(0), 'resolved' => true];
            }
            $product = Product::withoutGlobalScopes()->where(['company_id' => $locked->company_id, 'code' => $locked->product_code])->first();
            $missing = Quantity::units((string) $locked->missing);
            $apply = 0;

            if ($product !== null && $product->base_unit_id !== null) {
                [$available] = $this->ledger->balance($locked->company_id, $locked->branch_id, $product->id);
                $apply = min($missing, $available);
                if ($apply > 0) {
                    $this->ledger->record(
                        $locked->company_id, $locked->branch_id, $product, InventoryMovement::SALE, Quantity::format($apply), null, CarbonImmutable::today(), $user,
                        reference: 'Conciliación de faltante', note: "Venta del {$locked->issued_on->toDateString()} (línea {$locked->sales_line_id})",
                    );
                }
            }

            $left = $missing - $apply;
            $locked->forceFill([
                'product_id' => $product?->id, 'applied' => Quantity::format(Quantity::units((string) $locked->applied) + $apply), 'missing' => Quantity::format($left),
                'reason' => $product === null ? InventoryShortfall::NO_PRODUCT : ($product->base_unit_id === null ? InventoryShortfall::NO_BASE_UNIT : InventoryShortfall::INSUFFICIENT),
                'resolved_at' => $left === 0 ? now() : null, 'resolved_by' => $left === 0 ? $user->id : null,
            ])->save();
            $this->audit->record('inventory.shortfall_reconciled', $user, auditable: $locked, new: ['applied_now' => Quantity::display($apply), 'missing' => Quantity::display($left)], companyId: $locked->company_id);

            return ['applied' => Quantity::display($apply), 'missing' => Quantity::display($left), 'resolved' => $left === 0];
        });
    }

    /**
     * @param  array{branches: int, lines: int, movements: int, shortfalls: int}  $result
     */
    private function line(\stdClass $line, ?Product $product, array &$result): void
    {
        $need = Quantity::units((string) $line->units);
        if ($need <= 0) {
            return;
        }

        $apply = 0;
        $reason = InventoryShortfall::INSUFFICIENT;
        if ($product === null) {
            $reason = InventoryShortfall::NO_PRODUCT;
        } elseif ($product->base_unit_id === null) {
            $reason = InventoryShortfall::NO_BASE_UNIT;
        } else {
            [$available] = $this->ledger->balance((int) $line->company_id, (int) $line->branch_id, $product->id);
            $apply = min($need, $available);
            if ($apply > 0) {
                $this->ledger->record(
                    (int) $line->company_id, (int) $line->branch_id, $product, InventoryMovement::SALE, Quantity::format($apply), null, CarbonImmutable::parse($line->issued_on), null,
                    reference: "Venta {$line->series}-{$line->number}", sourceKey: 'sale:'.$line->id, audit: false,
                );
                $result['movements']++;
            }
        }

        if ($apply < $need) {
            InventoryShortfall::create([
                'company_id' => $line->company_id, 'branch_id' => $line->branch_id, 'product_id' => $product?->id, 'sales_line_id' => $line->id, 'product_code' => $line->product_code,
                'issued_on' => CarbonImmutable::parse($line->issued_on)->toDateString(), 'requested' => Quantity::format($need), 'applied' => Quantity::format($apply),
                'missing' => Quantity::format($need - $apply), 'reason' => $reason,
            ]);
            $result['shortfalls']++;
        }
    }
}
