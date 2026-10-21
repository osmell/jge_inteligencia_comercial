<?php

namespace App\Imports\Inventory;

use App\Imports\ColumnDefinition;
use App\Imports\Contracts\ImportType;
use App\Imports\Decimal;
use App\Imports\ImportContext;
use App\Imports\ImportRow;
use App\Imports\LocationResolver;
use App\Imports\ParsedRow;
use App\Inventory\Quantity;
use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\InventoryMovement;
use App\Models\InventoryReceipt;
use App\Models\User;
use App\Services\InventoryLedger;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Entradas de inventario (compras): un documento por compañía + proveedor + documento, con una fila por producto y unidad.
 *
 * Reglas:
 *  - Importes por línea: Sub Total = Cantidad × Costo − Descuento y Total = Sub Total + Iva (tolerancia de redondeo configurable);
 *    una diferencia es un error, no se corrige en silencio. No hay importes negativos. El IVA no entra al costo: el valor del
 *    inventario es el Sub Total.
 *  - Un documento ya cargado con el mismo contenido se omite; con contenido distinto es un error (no se sobrescribe: una
 *    corrección es una reversa). Un producto no puede repetirse en la misma unidad dentro de un documento.
 *  - Un documento fechado en o antes de la fecha de corte de su sucursal ya está contado en el inventario inicial: se omite con
 *    aviso para no contarlo dos veces. Una sucursal sin inventario inicial acepta la entrada, pero su existencia será parcial (aviso).
 */
final class InventoryEntriesImportType implements ImportType
{
    private const HINTS = [
        'received_on' => 'Fecha del documento de entrada.',
        'document' => 'Número del documento. Compañía + Proveedor + Documento identifican la entrada.',
        'supplier' => 'Nombre del proveedor.',
        'branch' => 'Código o nombre de la sucursal que recibe la mercancía.',
        'company' => 'Código o nombre de la compañía, tal como está registrada en el sistema.',
        'product_code' => 'Código del producto, tal como está en el catálogo (número entero).',
        'unit' => 'Unidad en que se recibe (por nombre o código). Si no es la unidad base del producto, debe tener equivalencia.',
        'quantity' => 'Cantidad recibida en esa unidad (hasta 8 decimales), mayor que cero.',
        'unit_cost' => 'Costo de una unidad de la unidad indicada (hasta 4 decimales).',
        'discount' => 'Descuento de la línea, en importe (vacío = 0).',
        'subtotal' => 'Cantidad × Costo − Descuento. Es el valor que entra al inventario.',
        'tax' => 'IVA de la línea (vacío = 0). No entra al costo.',
        'total' => 'Sub Total + Iva.',
        'observations' => 'Notas libres (hasta 500 caracteres).',
    ];

    private const AMOUNTS = ['unit_cost', 'discount', 'subtotal', 'tax', 'total'];

    private const MAX_COST_UNITS = 10_000_000_000;

    private ?User $user = null;

    private ?LocationResolver $resolver = null;

    private InventoryUnits $lookup;

    /** @var array<int, string|null> fecha de corte (primer inventario inicial) de cada sucursal; null si no tiene */
    private array $cutoffs = [];

    /** @var array<string, int> líneas vistas por documento, producto y unidad (para detectar repetidas) */
    private array $seen = [];

    /** @var array<int, true> sucursales sin inventario inicial que recibieron entradas */
    private array $withoutOpening = [];

    private int $skipped = 0;

    public function key(): string
    {
        return 'inventory_entries';
    }

    public function prepare(ImportBatch $batch): void
    {
        $this->user = $batch->user;
        $this->resolver = null;
        $this->lookup = new InventoryUnits;
        $this->cutoffs = [];
        $this->seen = [];
        $this->withoutOpening = [];
        $this->skipped = 0;
    }

    public function columns(): array
    {
        $optional = config('imports.inventory_entries.optional', []);

        return collect(config('imports.inventory_entries.columns'))
            ->map(fn (array $aliases, string $key) => new ColumnDefinition($key, $aliases, required: ! in_array($key, $optional, true), hint: self::HINTS[$key] ?? null))
            ->values()->all();
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $this->lookup ??= new InventoryUnits;
        $received = $row->date('received_on', config('imports.sales.date_formats'));
        $document = $this->limit($row, 'document', $row->code('document', warnNumeric: false), 100);
        $supplier = $this->limit($row, 'supplier', $row->text('supplier'), 255);
        $code = $row->code('product_code', warnNumeric: false);
        $unitText = $row->text('unit');
        $quantity = $row->decimal('quantity', scale: Quantity::SCALE);
        $observations = $this->limit($row, 'observations', $row->text('observations', required: false), 500);
        $amounts = [];
        foreach (self::AMOUNTS as $key) {
            $amounts[$key] = $row->decimal($key, required: ! in_array($key, ['discount', 'tax'], true), scale: 4);
        }
        $amounts['discount'] ??= Decimal::format(0);
        $amounts['tax'] ??= Decimal::format(0);
        [$company, $branch] = $this->resolver()->resolve($row);

        if ($row->failed() || ! $received || $document === null || $supplier === null || $code === null || $unitText === null || $quantity === null || ! $company || ! $branch) {
            return null;
        }

        $this->checkAmounts($row, $quantity, $amounts);
        if ($row->skipped() || $row->failed()) {
            return null;
        }
        $this->checkDate($row, $received);
        if ($row->failed()) {
            return null;
        }

        $cutoff = $this->cutoff($branch->id);
        if ($cutoff !== null && $received->format('Y-m-d') <= $cutoff) {
            $this->skipped++;
            $row->skip('received_on', 'before_cutoff', "El documento es de una fecha anterior o igual al corte del inventario inicial de la sucursal ({$cutoff}): ya está contado y se omite.");

            return null;
        }
        if ($cutoff === null) {
            $this->withoutOpening[$branch->id] = true;
        }

        [$product, $unit] = $this->lookup->resolve($row, $company, $code, $unitText, $quantity) ?? [null, null];
        if ($product === null || $unit === null) {
            return null;
        }

        $supplierKey = InventoryUnits::normalize($supplier);
        $groupKey = "{$company->id}|{$supplierKey}|{$document}";
        $lineKey = "{$groupKey}|{$product->id}|{$unit->id}";
        if (isset($this->seen[$lineKey]) && $this->seen[$lineKey] !== $row->number) {
            $row->fail('product_code', 'duplicate_line', "El producto {$product->code} ya aparece en la unidad «{$unit->name}» en este documento (fila {$this->seen[$lineKey]}). Sume las cantidades en una sola línea.");

            return null;
        }
        $this->seen[$lineKey] = $row->number;

        return new ParsedRow(
            $row->number,
            $groupKey,
            ['code' => $document, 'company' => (string) $company->id, 'branch' => (string) $branch->id, 'supplier' => $supplier, 'supplier_key' => $supplierKey, 'received_on' => $received->format('Y-m-d')],
            [
                'product_id' => $product->id, 'product_code' => $product->code, 'unit_id' => $unit->id, 'quantity' => $quantity, 'unit_cost' => $amounts['unit_cost'], 'discount' => $amounts['discount'],
                'subtotal' => $amounts['subtotal'], 'tax' => $amounts['tax'], 'total' => $amounts['total'], 'observations' => $observations,
            ],
        );
    }

    public function finalize(ImportContext $context): void
    {
        $existing = $this->existing($context);
        $lines = 0;

        foreach ($context->groups as $key => $group) {
            $lines += $group->linesCount();
            $hash = $group->contentHash();
            if (! isset($existing[$key])) {
                $context->documentsNew++;
            } elseif ($existing[$key] === $hash) {
                $context->documentsDuplicate++;
                $context->existingKeys[$key] = true;
            } else {
                $context->errors->error(
                    'document_conflict',
                    "El documento {$group->fields['code']} de «{$group->fields['supplier']}» ya existe con contenido distinto. No se sobrescribe: una corrección se registra como reversa de sus movimientos.",
                    $group->firstRow,
                    config('imports.inventory_entries.columns.document.0'),
                    $group->fields['code'],
                );
            }
        }

        if ($this->withoutOpening !== []) {
            $names = Branch::withoutGlobalScopes()->whereIn('id', array_keys($this->withoutOpening))->orderBy('name')->pluck('name')->all();
            $context->errors->warning('branch_without_opening', 'Sin inventario inicial en: '.implode(', ', $names).'. Las entradas se cargan, pero la existencia será parcial hasta cargar el inventario inicial.');
        }

        $context->summary = [...$context->summary, 'entries' => [
            'new' => $context->documentsNew, 'unchanged' => $context->documentsDuplicate, 'lines' => $lines, 'skipped_before_cutoff' => $this->skipped,
            'branches_without_opening' => Branch::withoutGlobalScopes()->whereIn('id', array_keys($this->withoutOpening))->orderBy('name')->pluck('name')->all(),
        ]];
    }

    public function persist(ImportContext $context): void
    {
        $ledger = app(InventoryLedger::class);
        $this->lookup ??= new InventoryUnits;
        $receipts = [];
        /** @var array<string, array{subtotal: int, discount: int, tax: int, total: int}> $totals */
        $totals = [];

        // Los importes del documento son la suma de sus líneas: se acumulan en una pasada y el documento se crea con la primera línea.
        foreach ($context->rows() as $row) {
            $key = $row->groupKey;
            if (isset($context->existingKeys[$key])) {
                continue;
            }
            $totals[$key] ??= ['subtotal' => 0, 'discount' => 0, 'tax' => 0, 'total' => 0];
            foreach (['subtotal', 'discount', 'tax', 'total'] as $field) {
                $totals[$key][$field] += Decimal::units($row->line[$field]);
            }
        }

        foreach ($context->rows() as $row) {
            $key = $row->groupKey;
            if (isset($context->existingKeys[$key])) {
                continue;
            }
            $group = $context->groups[$key];
            $f = $group->fields;
            $receiptId = $receipts[$key] ??= $this->createReceipt($context, $group->fields, $group->contentHash(), $group->linesCount(), $totals[$key]);
            $line = $row->line;
            $product = $this->lookup->product((int) $f['company'], $line['product_code']) ?? throw new \RuntimeException("El producto {$line['product_code']} ya no existe.");

            $ledger->record(
                (int) $f['company'], (int) $f['branch'], $product, InventoryMovement::ENTRY, $line['quantity'], $line['unit_id'], CarbonImmutable::parse($f['received_on']), $context->batch->user,
                value: $line['subtotal'], reference: mb_substr("{$f['supplier']} · {$f['code']}", 0, 100), note: $line['observations'],
                sourceKey: "entry:{$f['company']}:".substr(sha1($f['supplier_key']), 0, 16).":{$f['code']}:{$line['product_id']}:{$line['unit_id']}",
                batchId: $context->batch->id, audit: false, receiptId: $receiptId,
            );
        }
    }

    /**
     * @param  array<string, string|null>  $f
     * @param  array{subtotal: int, discount: int, tax: int, total: int}  $sum
     */
    private function createReceipt(ImportContext $context, array $f, string $hash, int $lines, array $sum): int
    {
        return DB::table('inventory_receipts')->insertGetId([
            'company_id' => (int) $f['company'], 'branch_id' => (int) $f['branch'], 'supplier' => $f['supplier'], 'supplier_key' => $f['supplier_key'], 'document' => $f['code'],
            'received_on' => $f['received_on'], 'subtotal' => Decimal::format($sum['subtotal']), 'discount_total' => Decimal::format($sum['discount']), 'tax_total' => Decimal::format($sum['tax']),
            'total' => Decimal::format($sum['total']), 'lines_count' => $lines, 'content_hash' => $hash, 'active_key' => "{$f['company']}|{$f['supplier_key']}|{$f['code']}", 'import_batch_id' => $context->batch->id, 'user_id' => $context->batch->user_id, 'created_at' => now(),
        ]);
    }

    /** @param  array<string, string|null>  $amounts */
    private function checkAmounts(ImportRow $row, string $quantity, array $amounts): void
    {
        if (Decimal::isNegative($quantity)) {
            $row->fail('quantity', 'negative_quantity', 'No se aceptan cantidades negativas: las devoluciones a proveedor se registran como movimientos aparte.');
        } elseif (! Decimal::isPositive($quantity)) {
            $row->skip('quantity', 'zero_quantity', 'Cantidad en cero: la fila se omite.');

            return;
        }
        foreach ($amounts as $key => $value) {
            if ($value !== null && Decimal::isNegative($value)) {
                $row->fail($key, 'negative_amount', 'No se aceptan importes negativos.');
            }
        }
        if ($row->failed()) {
            return;
        }
        if (Decimal::units((string) $amounts['unit_cost']) > self::MAX_COST_UNITS) {
            $row->fail('unit_cost', 'cost_too_large', 'El costo unitario supera 1.000.000.');

            return;
        }

        $tolerance = (int) round((float) config('imports.amount_tolerance') * 10 ** Decimal::SCALE);
        $show = fn (int $u) => number_format($u / 10 ** Decimal::SCALE, 2, '.', '');
        $expected = Quantity::extend(Quantity::units($quantity), Decimal::units((string) $amounts['unit_cost'])) - Decimal::units((string) $amounts['discount']);
        $subtotal = Decimal::units((string) $amounts['subtotal']);
        if (abs($subtotal - $expected) > $tolerance) {
            $row->fail('subtotal', 'amount_mismatch', sprintf('«%s» (%s) no coincide con «%s» × «%s» − «%s» (%s).', $row->label('subtotal'), $show($subtotal), $row->label('quantity'), $row->label('unit_cost'), $row->label('discount'), $show($expected)));
        }
        $expectedTotal = $subtotal + Decimal::units((string) $amounts['tax']);
        $total = Decimal::units((string) $amounts['total']);
        if (abs($total - $expectedTotal) > $tolerance) {
            $row->fail('total', 'amount_mismatch', sprintf('«%s» (%s) no coincide con «%s» + «%s» (%s).', $row->label('total'), $show($total), $row->label('subtotal'), $row->label('tax'), $show($expectedTotal)));
        }
    }

    private function checkDate(ImportRow $row, DateTimeImmutable $received): void
    {
        if ($received > new DateTimeImmutable('today')) {
            $row->fail('received_on', 'future_date', 'La fecha no puede ser futura.');
        } elseif ($received < new DateTimeImmutable('2000-01-01')) {
            $row->warn('received_on', 'old_date', 'La fecha es anterior al año 2000.');
        }
    }

    private function limit(ImportRow $row, string $key, ?string $value, int $max): ?string
    {
        if ($value !== null && mb_strlen($value) > $max) {
            $row->fail($key, 'too_long', "El texto supera los {$max} caracteres.");

            return null;
        }

        return $value;
    }

    /** Fecha de corte de la sucursal: la del primer inventario inicial; null si no tiene. */
    private function cutoff(int $branchId): ?string
    {
        if (! array_key_exists($branchId, $this->cutoffs)) {
            $date = InventoryMovement::withoutGlobalScopes()->where(['branch_id' => $branchId, 'type' => InventoryMovement::OPENING])->min('occurred_on');
            $this->cutoffs[$branchId] = $date === null ? null : CarbonImmutable::parse($date)->toDateString();
        }

        return $this->cutoffs[$branchId];
    }

    /** @return array<string, string> clave «compañía|proveedor|documento» => content_hash ya guardado */
    private function existing(ImportContext $context): array
    {
        $found = [];
        $byCompany = [];
        foreach ($context->groups as $group) {
            $byCompany[(int) $group->fields['company']][] = $group->fields['code'];
        }
        foreach ($byCompany as $companyId => $documents) {
            foreach (array_chunk(array_unique($documents), 500) as $chunk) {
                InventoryReceipt::withoutGlobalScopes()->whereNull('reversed_at')->where('company_id', $companyId)->whereIn('document', $chunk)->get(['company_id', 'supplier_key', 'document', 'content_hash'])
                    ->each(function (InventoryReceipt $r) use (&$found) {
                        $found["{$r->company_id}|{$r->supplier_key}|{$r->document}"] = $r->content_hash;
                    });
            }
        }

        return $found;
    }

    private function resolver(): LocationResolver
    {
        return $this->resolver ??= new LocationResolver($this->user);
    }
}
