<?php

namespace App\Imports\Inventory;

use App\Models\Product;
use App\Models\ProductUnit;
use App\Models\Unit;
use Illuminate\Support\Str;

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
}
