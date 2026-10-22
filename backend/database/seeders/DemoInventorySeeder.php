<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Imports\Decimal;
use App\Inventory\Quantity;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryLedger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Toma de prueba de inventario para desarrollo y demostraciones: `php artisan db:seed --class=DemoInventorySeeder`.
 * Parte de las ventas de demostración (ejecuta antes DemoSalesSeeder): crea los productos de la demo con su unidad base y sus
 * equivalencias, un inventario inicial el día anterior a la primera venta, entradas al inicio de cada mes (algunas en
 * cubetas, convertidas al galón) y las salidas de cada línea vendida. Nunca deja existencias negativas. Todo se marca con
 * la referencia «DEMO»; es repetible (borra y regenera solo lo marcado) y no se ejecuta en producción.
 */
class DemoInventorySeeder extends Seeder
{
    public const REFERENCE = 'DEMO';

    /** Equivalencias de los lubricantes y limpieza (se llevan en galones): 1 unidad = N galones. */
    private const LIQUID = ['barril' => '55', 'cubeta' => '5', 'litro' => '0.264172', 'octavo' => '0.125', '1_16' => '0.0625', '1_32' => '0.03125', '1_64' => '0.015625'];

    public function run(InventoryLedger $ledger): void
    {
        if (app()->isProduction()) {
            throw new RuntimeException('DemoInventorySeeder no puede ejecutarse en producción.');
        }
        $batch = DB::table('import_batches')->where('type', 'sales')->where('file_hash', hash('sha256', DemoSalesSeeder::FILE_HASH_SEED))->value('id')
            ?? throw new RuntimeException('No hay ventas de demostración: ejecuta antes DemoSalesSeeder.');
        $admin = User::where('username', 'admin')->first();

        // Sin una transacción única para todo: miles de movimientos en una sola agotan la memoria compartida de PostgreSQL.
        // Cada día se confirma por separado; si se interrumpe, se vuelve a ejecutar (borra lo marcado «DEMO» y regenera).
        DB::transaction(fn () => $this->purge());
        (function () use ($ledger, $batch, $admin) {
            $lines = DB::table('sales_lines as l')->join('sales_documents as d', 'd.id', '=', 'l.sales_document_id')->where('d.import_batch_id', $batch)
                ->orderBy('l.issued_on')->orderBy('l.id')->get(['l.id', 'l.company_id', 'l.branch_id', 'l.issued_on', 'l.product_code', 'l.product_name', 'l.category_name', 'l.units', 'l.unit_cost']);
            if ($lines->isEmpty()) {
                throw new RuntimeException('Las ventas de demostración no tienen líneas.');
            }

            $products = $this->products($lines);
            $units = Unit::pluck('id', 'code');
            $demand = [];
            $cost = [];
            foreach ($lines as $l) {
                $month = substr((string) $l->issued_on, 0, 7);
                $key = "{$l->company_id}|{$l->branch_id}|{$l->product_code}";
                $demand[$key][$month] = ($demand[$key][$month] ?? 0) + Quantity::units((string) $l->units);
                $cost[$l->product_code][] = Decimal::units((string) $l->unit_cost);
            }

            $first = CarbonImmutable::parse((string) $lines->first()->issued_on);
            $firstMonth = $first->format('Y-m');
            $byDay = $lines->groupBy(fn ($l) => substr((string) $l->issued_on, 0, 10));
            $seenMonth = [];

            foreach ($byDay as $day => $dayLines) {
                DB::transaction(function () use ($ledger, $admin, $units, $demand, $cost, $products, $first, $firstMonth, &$seenMonth, $day, $dayLines) {
                    $month = substr($day, 0, 7);
                    if (! isset($seenMonth[$month])) {
                        $seenMonth[$month] = true;
                        // Inventario inicial (día anterior a la primera venta) o entradas del mes, solo donde habrá salidas.
                        foreach ($demand as $key => $perMonth) {
                            if (! isset($perMonth[$month])) {
                                continue;
                            }
                            [$companyId, $branchId, $code] = explode('|', $key, 3);
                            $product = $products[$companyId.'|'.$code];
                            $isOpening = $month === $firstMonth;
                            $on = $isOpening ? $first->subDay() : CarbonImmutable::parse($day);
                            $this->stock($ledger, $admin, $units, (int) $companyId, (int) $branchId, $product, $perMonth[$month], $isOpening, $on, $month, $cost[$code]);
                        }
                    }
                    foreach ($dayLines as $l) {
                        $ledger->record($l->company_id, $l->branch_id, $products[$l->company_id.'|'.$l->product_code], InventoryMovement::SALE, (string) $l->units, null, CarbonImmutable::parse($day), null,
                            reference: self::REFERENCE, sourceKey: 'sale:'.$l->id, audit: false);
                    }
                });
            }
        })();
    }

    /** Inventario inicial o entrada mensual que cubre la demanda del mes (no registra nada si ya alcanza). */
    private function stock(InventoryLedger $ledger, ?User $admin, $units, int $companyId, int $branchId, Product $product, int $demand, bool $opening, CarbonImmutable $on, string $month, array $costs): void
    {
        [$balance] = $ledger->balance($companyId, $branchId, $product->id);
        $factor = 1.15 + (crc32($product->code) % 100) / 100;
        $needed = (int) ceil($demand * $factor) - $balance;
        if ($needed <= 0) {
            return;
        }

        $liquid = $product->base_unit_id === $units['galon'];
        $unitId = null;
        $enteredUnits = $needed;
        $baseUnits = $needed;
        if ($liquid) {
            // Se compra en cubetas de 5 galones.
            $buckets = (int) ceil($needed / (5 * 10 ** Quantity::SCALE));
            $unitId = $units['cubeta'];
            $enteredUnits = $buckets * 10 ** Quantity::SCALE;
            $baseUnits = $buckets * 5 * 10 ** Quantity::SCALE;
        }
        $unitCost = (int) round(array_sum($costs) / count($costs));
        $noise = 0.95 + (crc32($product->code.$month) % 11) / 100;    // ±5 % de variación del costo entre compras
        $value = Decimal::format((int) round($baseUnits / 10 ** Quantity::SCALE * $unitCost * $noise));

        $ledger->record(
            $companyId, $branchId, $product, $opening ? InventoryMovement::OPENING : InventoryMovement::ENTRY, Quantity::display($enteredUnits), $unitId, $on, $admin,
            value: $value, reference: self::REFERENCE, note: $opening ? 'Toma de prueba' : null, sourceKey: ($opening ? 'demo:opening:' : 'demo:entry:')."{$companyId}:{$branchId}:{$product->code}:{$month}", audit: false,
        );

    }

    /** @return array<string, Product> «compañía|código» => producto, con unidad base y equivalencias. */
    private function products($lines): array
    {
        $units = Unit::pluck('id', 'code');
        $out = [];
        foreach ($lines->unique(fn ($l) => $l->company_id.'|'.$l->product_code) as $l) {
            $liquid = in_array($l->category_name, ['Lubricantes', 'Limpieza'], true);
            $product = Product::withoutGlobalScopes()->firstOrCreate(['company_id' => $l->company_id, 'code' => $l->product_code], ['name' => $l->product_name, 'level2' => $l->category_name]);
            if ($product->base_unit_id === null) {
                $product->forceFill(['base_unit_id' => $units[$liquid ? 'galon' : 'unidad']])->save();
                if ($liquid) {
                    foreach (self::LIQUID as $unit => $factor) {
                        [$num, $den] = Quantity::factor($factor);
                        ProductUnit::firstOrCreate(['product_id' => $product->id, 'unit_id' => $units[$unit]], ['factor_num' => $num, 'factor_den' => $den]);
                    }
                }
            }
            $out[$l->company_id.'|'.$l->product_code] = $product;
        }

        return $out;
    }

    /** Borra solo los movimientos marcados «DEMO» y los saldos de los productos que tocaron (los movimientos son inmutables desde los modelos). */
    private function purge(): void
    {
        $touched = DB::table('inventory_movements')->where('reference', self::REFERENCE)->distinct()->pluck('product_id');
        // Las reversas apuntan al movimiento original: se borran primero.
        DB::table('inventory_movements')->where('reference', self::REFERENCE)->whereNotNull('reverses_id')->delete();
        DB::table('inventory_movements')->where('reference', self::REFERENCE)->delete();
        foreach ($touched->chunk(500) as $chunk) {
            DB::table('inventory_balances')->whereIn('product_id', $chunk)->delete();
            // Si quedó algún movimiento real de esos productos, su saldo se reconstruye.
            foreach (DB::table('inventory_movements')->whereIn('product_id', $chunk)->selectRaw('company_id, branch_id, product_id')->distinct()->get() as $k) {
                $rows = DB::table('inventory_movements')->where(['company_id' => $k->company_id, 'branch_id' => $k->branch_id, 'product_id' => $k->product_id])->get(['quantity', 'value']);
                DB::table('inventory_balances')->insert([
                    'company_id' => $k->company_id, 'branch_id' => $k->branch_id, 'product_id' => $k->product_id, 'updated_at' => now(),
                    'quantity' => Quantity::format($rows->sum(fn ($r) => Quantity::units((string) $r->quantity))), 'value' => Decimal::format($rows->sum(fn ($r) => Decimal::units((string) $r->value))),
                ]);
            }
        }
    }
}
