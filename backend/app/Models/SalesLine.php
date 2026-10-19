<?php

namespace App\Models;

use App\Models\Concerns\Immutable;
use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de una factura de venta. Los importes se guardan como vienen en el archivo.
 *
 * @property int $id
 * @property int $sales_document_id
 * @property string $product_code
 * @property string $category_name
 * @property string $units
 * @property string $unit_cost
 * @property string $net_total
 */
#[Fillable([
    'sales_document_id', 'company_id', 'branch_id', 'issued_on', 'line_number', 'product_code', 'product_name', 'category_name',
    'units', 'unit_cost', 'line_cost_total', 'unit_price', 'normal_price', 'net_total', 'tax_total', 'sale_plus_tax', 'grand_total',
])]
class SalesLine extends Model
{
    use Immutable, ScopedByOrganization;

    protected function casts(): array
    {
        return [
            'issued_on' => 'date',
            'units' => 'decimal:4', 'unit_cost' => 'decimal:4', 'line_cost_total' => 'decimal:4', 'unit_price' => 'decimal:4',
            'normal_price' => 'decimal:4', 'net_total' => 'decimal:4', 'tax_total' => 'decimal:4',
            'sale_plus_tax' => 'decimal:4', 'grand_total' => 'decimal:4',
        ];
    }

    /** @return BelongsTo<SalesDocument, $this> */
    public function document(): BelongsTo
    {
        return $this->belongsTo(SalesDocument::class, 'sales_document_id');
    }
}
