<?php

declare(strict_types=1);

namespace App\Imports\Inventory;

use App\Imports\ImportRow;
use App\Inventory\Quantity;
use App\Models\Company;
use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Illuminate\Support\Str;
use OverflowException;

/** Búsquedas con caché que comparten las importaciones de inventario: unidades por nombre o código, productos y sus equivalencias. */
final class InventoryUnits
{
    /** @var array<string, Unit>|null */
    private ?array $units = null;

    /** @var array<string, Product|null> */
    private array $products = [];

    /** @var array<int, array<int, ProductUnit>> */
    private array $equivalences = [];

    public static function normalize(string $text): string
    {
        return Str::ascii(mb_strtolower((string) preg_replace('/\s+/u', ' ', trim($text))));
    }

    /** Unidad activa por nombre o código, sin distinguir mayúsculas ni acentos. */
    public function unit(string $text): ?Unit
    {
        if ($this->units === null) {
            $this->units = [];
            $all = Unit::query()->where('active', true)->get();
            foreach ($all as $unit) {
                $this->units[self::normalize($unit->code)] = $unit;
            }
            foreach ($all as $unit) {
                $this->units[self::normalize($unit->name)] ??= $unit;
            }
        }

        return $this->units[self::normalize($text)] ?? null;
    }

    public function product(int $companyId, string $code): ?Product
    {
        return $this->products["$companyId|$code"] ??= Product::withoutGlobalScopes()->where(['company_id' => $companyId, 'code' => $code])->first();
    }

    /** @return array<int, ProductUnit> equivalencias del producto por id de unidad */
    public function equivalences(int $productId): array
    {
        return $this->equivalences[$productId] ??= ProductUnit::query()->where('product_id', $productId)->get()->keyBy('unit_id')->all();
    }

    /**
     * Producto y unidad de una fila con todas las reglas comunes de las cargas de inventario: el producto existe y tiene unidad
     * base, la unidad existe y, si no es la base, tiene equivalencia, y la cantidad (y su conversión a la base) no pasa de 10.000.000.
     * Registra los errores en la fila y devuelve null si hay alguno.
     *
     * @return array{0: Product, 1: Unit}|null
     */
    public function resolve(ImportRow $row, Company $company, string $code, string $unitText, string $quantity): ?array
    {
        $product = $this->product($company->id, $code);
        if ($product === null) {
            $row->fail('product_code', 'unknown_product', "El producto {$code} no existe en el catálogo de «{$company->name}». Cargue antes el catálogo de productos.");
        } elseif ($product->base_unit_id === null) {
            $row->fail('product_code', 'no_base_unit', "El producto {$code} no tiene unidad base: defínela en Inventario → Unidades o con la plantilla «Unidades y equivalencias».");
        }
        $unit = $this->unit($unitText);
        if ($unit === null) {
            $row->fail('unit', 'unknown_unit', "La unidad «{$unitText}» no existe o está inactiva (se busca por nombre o código).");
        }
        if ($row->failed() || $product === null || $unit === null) {
            return null;
        }

        $entered = Quantity::units($quantity);
        if ($entered > Quantity::MAX) {
            $row->fail('quantity', 'quantity_too_large', 'La cantidad supera 10.000.000.');

            return null;
        }
        if ($unit->id !== $product->base_unit_id) {
            $equivalence = $this->equivalences($product->id)[$unit->id] ?? null;
            if ($equivalence === null) {
                $row->fail('unit', 'no_equivalence', "El producto {$code} no tiene equivalencia para «{$unit->name}». Defínela en Inventario → Unidades o con la plantilla «Unidades y equivalencias».");

                return null;
            }
            try {
                if (Quantity::convert($entered, $equivalence->factor_num, $equivalence->factor_den) > Quantity::MAX) {
                    $row->fail('quantity', 'quantity_too_large', 'La cantidad convertida a la unidad base supera 10.000.000.');

                    return null;
                }
            } catch (OverflowException) {
                $row->fail('quantity', 'quantity_too_large', 'La cantidad convertida a la unidad base es demasiado grande.');

                return null;
            }
        }

        return [$product, $unit];
    }
}
