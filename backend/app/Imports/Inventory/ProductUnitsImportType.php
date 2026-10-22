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
use App\Models\Company;
use App\Models\ImportBatch;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Seller;
use App\Models\User;
use App\Services\DataScope;
use InvalidArgumentException;

/**
 * Unidad base y equivalencias por producto. Una fila por equivalencia («Unidad» y «Factor»: 1 unidad vale «Factor» unidades
 * base); una fila sin «Unidad» fija solo la base. Agrega y actualiza; no borra equivalencias que el archivo no menciona. El
 * producto debe existir en el catálogo y la unidad base no se cambia si el producto ya tiene movimientos de inventario.
 */
final class ProductUnitsImportType implements ImportType
{
    private const HINTS = [
        'company' => 'Código o nombre de la compañía, tal como está registrada en el sistema.',
        'product_code' => 'Código del producto, tal como está en el catálogo (número entero).',
        'base_unit' => 'Unidad en la que se lleva el inventario del producto (p. ej. Galón). Igual en todas las filas del producto.',
        'unit' => 'Unidad con equivalencia (barril, cubeta, litro, 1/16…). Vacía = solo fija la unidad base.',
        'factor' => 'Cuántas unidades base vale 1 de «Unidad» (hasta 6 decimales; p. ej. 1 cubeta = 5 galones → 5; 1/16 → 0.0625).',
    ];

    private ?User $user = null;

    private ?LocationResolver $resolver = null;

    private InventoryUnits $lookup;

    /** @var array<string, array{0: int, 1: int}> unidad base declarada por producto y la fila que la fijó */
    private array $bases = [];

    public function __construct(private readonly DataScope $scope) {}

    public function key(): string
    {
        return 'product_units';
    }

    public function prepare(ImportBatch $batch): void
    {
        $this->user = $batch->user;
        $this->resolver = null;
        $this->lookup = new InventoryUnits;
        $this->bases = [];
    }

    public function columns(): array
    {
        $optional = config('imports.product_units.optional', []);

        return collect((array) config('imports.product_units.columns'))
            ->map(fn (array $aliases, string $key) => new ColumnDefinition($key, $aliases, required: ! in_array($key, $optional, true), hint: self::HINTS[$key] ?? null))
            ->values()->all();
    }

    public function parseRow(ImportRow $row): ?ParsedRow
    {
        $this->lookup ??= new InventoryUnits;
        $code = $row->code('product_code', warnNumeric: false);
        $baseText = $row->text('base_unit');
        $unitText = $row->text('unit', required: false);
        $factorText = $row->decimal('factor', required: false, scale: Quantity::FACTOR_SCALE);
        $company = $this->company($row);

        if ($row->failed() || $code === null || $baseText === null || ! $company) {
            return null;
        }

        $product = $this->lookup->product($company->id, $code);
        if ($product === null) {
            $row->fail('product_code', 'unknown_product', "El producto {$code} no existe en el catálogo de «{$company->name}». Cargue antes el catálogo de productos.");
        }
        $base = $this->lookup->unit($baseText);
        if ($base === null) {
            $row->fail('base_unit', 'unknown_unit', "La unidad «{$baseText}» no existe o está inactiva (se busca por nombre o código).");
        }
        $unit = $unitText === null ? null : $this->lookup->unit($unitText);
        if ($unitText !== null && $unit === null) {
            $row->fail('unit', 'unknown_unit', "La unidad «{$unitText}» no existe o está inactiva (se busca por nombre o código).");
        }
        if ($row->failed() || $product === null || $base === null) {
            return null;
        }

        $this->checkBase($row, $company->id.'|'.$code, $base->id);
        [$num, $den] = $this->factor($row, $unit?->id === $base->id || $unit === null, $factorText);
        if ($row->failed()) {
            return null;
        }

        $isBaseOnly = $unit === null || $unit->id === $base->id;

        return new ParsedRow(
            $row->number,
            "{$company->id}|{$product->id}|".($isBaseOnly ? 'base' : $unit->id),
            ['code' => $product->code, 'company' => (string) $company->id, 'product_id' => (string) $product->id, 'base_unit_id' => (string) $base->id, 'unit_id' => $isBaseOnly ? null : (string) $unit->id, 'factor_num' => $num === null ? null : (string) $num, 'factor_den' => $den === null ? null : (string) $den],
            [],
        );
    }

    public function finalize(ImportContext $context): void
    {
        $products = Product::withoutGlobalScopes()->whereIn('id', array_unique(array_map(fn ($g) => (int) $g->fields['product_id'], $context->groups)))->get()->keyBy('id');
        $withMovements = InventoryMovement::withoutGlobalScopes()->whereIn('product_id', $products->keys())->distinct()->pluck('product_id')->flip();
        $equivalences = ProductUnit::query()->whereIn('product_id', $products->keys())->get()->groupBy('product_id')->map(fn ($rows) => $rows->keyBy('unit_id'));
        $counts = ['bases' => 0, 'new' => 0, 'updated' => 0, 'unchanged' => 0];
        $baseChecked = [];

        foreach ($context->groups as $key => $group) {
            $f = $group->fields;
            $product = $products[(int) $f['product_id']];
            $baseChanges = $product->base_unit_id !== (int) $f['base_unit_id'];
            if ($baseChanges && $product->base_unit_id !== null && isset($withMovements[$product->id])) {
                $context->errors->error('base_unit_locked', "El producto {$f['code']} ya tiene movimientos de inventario: su unidad base no se puede cambiar.", $group->firstRow, config('imports.product_units.columns.base_unit.0'), $f['code']);

                continue;
            }
            if ($baseChanges && ! isset($baseChecked[$product->id])) {
                $baseChecked[$product->id] = true;
                $counts['bases']++;
            }

            if ($f['unit_id'] === null) {
                if ($baseChanges) {
                    $context->documentsNew++;
                } else {
                    $context->documentsDuplicate++;
                    $context->existingKeys[$key] = true;
                }

                continue;
            }
            $current = $equivalences[$product->id][(int) $f['unit_id']] ?? null;
            if ($current === null) {
                $counts['new']++;
                $context->documentsNew++;
            } elseif ($current->factor_num === (int) $f['factor_num'] && $current->factor_den === (int) $f['factor_den'] && ! $baseChanges) {
                $counts['unchanged']++;
                $context->documentsDuplicate++;
                $context->existingKeys[$key] = true;
            } else {
                $counts['updated']++;
                $context->documentsNew++;
            }
        }

        $context->summary = [...$context->summary, 'units' => ['bases_set' => $counts['bases'], 'new' => $counts['new'], 'updated' => $counts['updated'], 'unchanged' => $counts['unchanged']]];
    }

    public function persist(ImportContext $context): void
    {
        foreach ($context->groups as $key => $group) {
            if (isset($context->existingKeys[$key])) {
                continue;
            }
            $f = $group->fields;
            Product::withoutGlobalScopes()->whereKey((int) $f['product_id'])->update(['base_unit_id' => (int) $f['base_unit_id'], 'updated_at' => now()]);
            if ($f['unit_id'] !== null) {
                ProductUnit::query()->updateOrCreate(['product_id' => (int) $f['product_id'], 'unit_id' => (int) $f['unit_id']], ['factor_num' => (int) $f['factor_num'], 'factor_den' => (int) $f['factor_den']]);
            }
        }
    }

    /** Un producto tiene una sola unidad base en todo el archivo. */
    private function checkBase(ImportRow $row, string $productKey, int $baseId): void
    {
        [$first, $firstRow] = $this->bases[$productKey] ??= [$baseId, $row->number];
        if ($first !== $baseId) {
            $row->fail('base_unit', 'base_unit_mismatch', "El producto tiene otra unidad base en la fila {$firstRow}: debe ser la misma en todas sus filas.");
        }
    }

    /** @return array{0: int|null, 1: int|null} numerador y denominador reducidos; null cuando la fila solo fija la base */
    private function factor(ImportRow $row, bool $baseOnly, ?string $factorText): array
    {
        if ($baseOnly) {
            if ($factorText !== null && Decimal::units($factorText) !== 10 ** Decimal::SCALE) {
                $row->fail('factor', 'base_factor', 'La unidad base no lleva factor (vacío o 1).');
            }

            return [null, null];
        }
        if ($factorText === null) {
            $row->fail('factor', 'required', 'El factor es obligatorio cuando se indica una unidad equivalente.');

            return [null, null];
        }
        try {
            return Quantity::factor(rtrim(rtrim($factorText, '0'), '.') ?: '0');
        } catch (InvalidArgumentException $e) {
            $row->fail('factor', 'invalid_factor', $e->getMessage());

            return [null, null];
        }
    }

    private function company(ImportRow $row): ?Company
    {
        $text = $row->text('company');
        if ($text === null) {
            return null;
        }
        $company = $this->resolver()->companies()[Seller::normalize($text)] ?? null;
        if (! $company) {
            $row->fail('company', 'unknown_company', "La compañía «{$text}» no existe (se busca por código o nombre).");

            return null;
        }
        if (! $company->is_active) {
            $row->fail('company', 'inactive_company', "La compañía «{$text}» está inactiva.");

            return null;
        }
        $allowed = $this->user ? $this->scope->fullCompanyIds($this->user) : null;
        if ($allowed !== null && ! in_array($company->id, $allowed, true)) {
            $row->fail('company', 'company_out_of_scope', "No tienes acceso completo a la compañía «{$text}».");

            return null;
        }

        return $company;
    }

    private function resolver(): LocationResolver
    {
        return $this->resolver ??= new LocationResolver($this->user, $this->scope);
    }
}
