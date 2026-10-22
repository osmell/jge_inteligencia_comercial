<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Product;
use App\Models\ProductUnit;
use Illuminate\Support\Facades\DB;

/** Unidad base y equivalencias de un producto, guardadas de una sola vez. */
class ProductUnitsEditor
{
    /** @param  array<int, array{factor_num: int, factor_den: int}>  $equivalences  por id de unidad */
    public function save(Product $product, int $baseUnitId, array $equivalences): void
    {
        DB::transaction(function () use ($product, $baseUnitId, $equivalences) {
            $product->forceFill(['base_unit_id' => $baseUnitId])->save();
            $product->equivalences()->whereNotIn('unit_id', array_keys($equivalences))->delete();
            foreach ($equivalences as $unitId => $factor) {
                ProductUnit::query()->updateOrCreate(['product_id' => $product->id, 'unit_id' => $unitId], $factor);
            }
        });
    }
}
