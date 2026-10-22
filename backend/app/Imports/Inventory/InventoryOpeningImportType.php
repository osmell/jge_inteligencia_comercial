<?php

declare(strict_types=1);

namespace App\Imports\Inventory;

use App\Imports\ColumnDefinition;
use App\Imports\Contracts\ImportType;
use App\Imports\Decimal;
use App\Imports\ImportContext;
use App\Imports\ImportRow;
use App\Imports\LocationResolver;
use App\Imports\ParsedRow;
use App\Inventory\Quantity;
use App\Jobs\SyncSalesInventoryJob;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\InventoryMovement;
use App\Models\User;
use App\Services\InventoryLedger;
use Carbon\CarbonImmutable;
use DateTimeImmutable;

/**
 * Inventario inicial: una fila por producto, unidad y sucursal, con la fecha de corte (única por sucursal), la cantidad en la
 * unidad indicada y el costo unitario en esa unidad. Cada fila es un movimiento «inicial» del libro, con una clave de origen
 * propia: volver a cargar el mismo archivo no duplica nada y un contenido distinto sobre un producto que ya tiene inventario
 * inicial se rechaza (un cambio se registra como ajuste, no se sobrescribe). Las filas con cantidad cero se omiten con aviso.
 */
final class InventoryOpeningImportType implements ImportType
{
    private const HINTS = [
        'cutoff' => 'Fecha del conteo. Debe ser la misma para toda la sucursal; las ventas anteriores no descuentan existencias.',
        'product_code' => 'Código del producto, tal como está en el catálogo (número entero).',
        'unit' => 'Unidad en que se cuenta (por nombre o código). Si no es la unidad base del producto, debe tener equivalencia.',
        'quantity' => 'Cantidad contada en esa unidad (hasta 8 decimales). Cero se omite; no se aceptan negativos.',
        'unit_cost' => 'Costo de una unidad de la unidad indicada (hasta 4 decimales). Arranca el costo promedio ponderado.',
        'branch' => 'Código o nombre de la sucursal donde está el inventario.',
        'company' => 'Código o nombre de la compañía, tal como está registrada en el sistema.',
    ];

    private const MAX_COST_UNITS = 10_000_000_000;      // 1.000.000,0000 por unidad

    private ?User $user = null;

    private ?LocationResolver $resolver = null;

    private InventoryUnits $lookup;

    /** @var array<int, array{0: string, 1: int}> fecha de corte y fila que la fijó, por sucursal */
    private array $cutoffs = [];

    public function key(): string
    {
        return 'inventory_opening';
    }

    public function prepare(ImportBatch $batch): void
    {
        $this->user = $batch->user;
        $this->resolver = null;
        $this->lookup = new InventoryUnits;
        $this->cutoffs = [];
    }

    public function columns(): array
    {
        return collect(config('imports.inventory_opening.columns'))
            ->map(fn (array $aliases, string $key) => new ColumnDefinition($key, $aliases, hint: self::HINTS[$key] ?? null))
            ->values()->all();
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $this->lookup ??= new InventoryUnits;
        $cutoff = $row->date('cutoff', config('imports.sales.date_formats'));
        $code = $row->code('product_code', warnNumeric: false);
        $unitText = $row->text('unit');
        $quantity = $row->decimal('quantity', scale: Quantity::SCALE);
        $cost = $row->decimal('unit_cost', scale: 4);
        [$company, $branch] = $this->resolver()->resolve($row);

        if ($row->failed() || ! $cutoff || $code === null || $unitText === null || $quantity === null || $cost === null || ! $company || ! $branch) {
            return null;
        }

        $this->checkAmounts($row, $quantity, $cost);
        if ($row->skipped()) {
            return null;
        }
        $this->checkCutoff($row, $cutoff, $branch->id);
        [$product, $unit] = $this->lookup->resolve($row, $company, $code, $unitText, $quantity) ?? [null, null];
        if ($product === null || $unit === null) {
            return null;
        }

        return new ParsedRow(
            $row->number,
            "{$company->id}|{$branch->id}|{$product->id}|{$unit->id}",
            ['code' => $product->code, 'company' => (string) $company->id, 'branch' => (string) $branch->id, 'product_id' => (string) $product->id, 'unit_id' => (string) $unit->id, 'cutoff' => $cutoff->format('Y-m-d'), 'quantity' => $quantity, 'unit_cost' => $cost],
            [],
        );
    }

    public function finalize(ImportContext $context): void
    {
        $existing = $this->existing($context);
        $branches = [];
        $changed = 0;

        foreach ($context->groups as $key => $group) {
            $f = $group->fields;
            $mine = $existing["{$f['company']}|{$f['branch']}|{$f['product_id']}"] ?? [];
            $branches[$f['branch']] = ($branches[$f['branch']] ?? 0) + 1;
            if ($mine === []) {
                $context->documentsNew++;

                continue;
            }
            $same = collect($mine)->contains(fn (InventoryMovement $m) => $m->source_key === $this->sourceKey($f)
                && Quantity::units((string) $m->entered_quantity) === Quantity::units($f['quantity']) && $m->occurred_on->toDateString() === $f['cutoff']
                && Decimal::units((string) $m->value) === $this->value($f));
            if ($same) {
                $context->documentsDuplicate++;
                $context->existingKeys[$key] = true;

                continue;
            }
            $changed++;
            $context->errors->error(
                'opening_exists',
                "El producto {$f['code']} ya tiene inventario inicial en esa sucursal con otros datos. No se sobrescribe: un cambio se registra como ajuste.",
                $group->firstRow,
                config('imports.inventory_opening.columns.product_code.0'),
                $f['code'],
            );
        }

        // Lo que ya se movió hasta la fecha de corte quedaría contado dos veces: la fecha debe ser posterior.
        foreach ($branches as $branchId => $count) {
            $cutoff = $this->cutoffs[(int) $branchId][0] ?? null;
            $row = $this->cutoffs[(int) $branchId][1] ?? null;
            if ($cutoff !== null && InventoryMovement::withoutGlobalScopes()->where('branch_id', $branchId)->where('type', '!=', InventoryMovement::OPENING)->where('occurred_on', '<=', $cutoff)->exists()) {
                $context->errors->error('movements_before_cutoff', "La sucursal ya tiene movimientos de inventario fechados hasta el {$cutoff}: el inventario inicial contaría dos veces lo ya movido. Use una fecha de corte posterior.", $row, config('imports.inventory_opening.columns.cutoff.0'), $cutoff);
            }
        }

        $names = Branch::withoutGlobalScopes()->whereIn('id', array_keys($branches))->pluck('name', 'id');
        $context->summary = [...$context->summary, 'inventory' => [
            'new' => $context->documentsNew, 'unchanged' => $context->documentsDuplicate, 'conflicts' => $changed,
            'branches' => collect($branches)->map(fn (int $count, int|string $id) => ['id' => (int) $id, 'name' => $names[$id] ?? '', 'products' => $count, 'cutoff' => $this->cutoffs[(int) $id][0] ?? null])->values()->all(),
        ]];
    }

    public function persist(ImportContext $context): void
    {
        $ledger = app(InventoryLedger::class);
        $this->lookup ??= new InventoryUnits;

        foreach ($context->groups as $key => $group) {
            if (isset($context->existingKeys[$key])) {
                continue;
            }
            $f = $group->fields;
            $product = $this->lookup->product((int) $f['company'], $f['code']) ?? throw new \RuntimeException("El producto {$f['code']} ya no existe.");
            $ledger->record(
                (int) $f['company'], (int) $f['branch'], $product, InventoryMovement::OPENING, $f['quantity'], (int) $f['unit_id'], CarbonImmutable::parse($f['cutoff']), $context->batch->user,
                value: Decimal::format($this->value($f)), reference: 'Inventario inicial', sourceKey: $this->sourceKey($f), batchId: $context->batch->id, audit: false,
            );
        }

        // Las ventas posteriores a la fecha de corte que ya estaban cargadas descuentan ahora, en segundo plano.
        foreach (collect($context->groups)->map(fn ($g) => (int) $g->fields['branch'])->unique() as $branchId) {
            SyncSalesInventoryJob::dispatch(null, $branchId)->afterCommit();
        }
    }

    private function checkAmounts(ImportRow $row, string $quantity, string $cost): void
    {
        if (Decimal::isNegative($quantity)) {
            $row->fail('quantity', 'negative_quantity', 'No se aceptan cantidades negativas: no se permiten existencias en negativo.');
        } elseif (! Decimal::isPositive($quantity)) {
            $row->skip('quantity', 'zero_quantity', 'Cantidad en cero: la fila se omite.');
        }
        if (Decimal::isNegative($cost)) {
            $row->fail('unit_cost', 'negative_cost', 'El costo no puede ser negativo.');
        } elseif (Decimal::units($cost) > self::MAX_COST_UNITS) {
            $row->fail('unit_cost', 'cost_too_large', 'El costo unitario supera 1.000.000.');
        }
    }

    private function checkCutoff(ImportRow $row, DateTimeImmutable $cutoff, int $branchId): void
    {
        $day = $cutoff->format('Y-m-d');
        if ($cutoff > new DateTimeImmutable('today')) {
            $row->fail('cutoff', 'future_cutoff', 'La fecha de corte no puede ser futura.');

            return;
        }
        [$first, $firstRow] = $this->cutoffs[$branchId] ??= [$day, $row->number];
        if ($first !== $day) {
            $row->fail('cutoff', 'cutoff_mismatch', "La sucursal ya tiene fecha de corte {$first} (fila {$firstRow}): debe ser una sola por sucursal.");
        }
    }

    /** @param  array<string, string|null>  $f */
    private function value(array $f): int
    {
        return Quantity::extend(Quantity::units((string) $f['quantity']), Decimal::units((string) $f['unit_cost']));
    }

    /** @param  array<string, string|null>  $f */
    private function sourceKey(array $f): string
    {
        return "opening:{$f['company']}:{$f['branch']}:{$f['product_id']}:{$f['unit_id']}";
    }

    /**
     * Inventarios iniciales que ya existen para los productos y sucursales del archivo.
     *
     * @return array<string, list<InventoryMovement>> clave «compañía|sucursal|producto»
     */
    private function existing(ImportContext $context): array
    {
        $found = [];
        $productIds = array_unique(array_map(fn ($g) => (int) $g->fields['product_id'], $context->groups));
        foreach (array_chunk($productIds, 500) as $chunk) {
            InventoryMovement::withoutGlobalScopes()->where('type', InventoryMovement::OPENING)->whereIn('product_id', $chunk)->get()
                ->each(function (InventoryMovement $m) use (&$found) {
                    $found["{$m->company_id}|{$m->branch_id}|{$m->product_id}"][] = $m;
                });
        }

        return $found;
    }

    private function resolver(): LocationResolver
    {
        return $this->resolver ??= new LocationResolver($this->user);
    }
}
