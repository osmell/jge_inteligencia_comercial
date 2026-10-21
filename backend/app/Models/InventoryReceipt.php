<?php

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Documento de entrada de inventario (compra a un proveedor). Los importes son sumas de sus líneas; el IVA no entra al costo.
 *
 * @property int $id
 * @property int $company_id
 * @property int $branch_id
 * @property string $supplier
 * @property string $supplier_key
 * @property string $document
 * @property Carbon $received_on
 * @property string $subtotal
 * @property string $discount_total
 * @property string $tax_total
 * @property string $total
 * @property int $lines_count
 * @property string $content_hash
 */
#[Fillable(['company_id', 'branch_id', 'supplier', 'supplier_key', 'document', 'received_on', 'subtotal', 'discount_total', 'tax_total', 'total', 'lines_count', 'content_hash', 'import_batch_id', 'user_id', 'created_at', 'active_key', 'reversed_at', 'reversed_by'])]
class InventoryReceipt extends Model
{
    // No es inmutable: solo cambian reversed_at, reversed_by y active_key, y solo desde InventoryOperations.
    use ScopedByOrganization;

    public $timestamps = false;

    protected function casts(): array
    {
        return ['received_on' => 'date:Y-m-d', 'created_at' => 'datetime', 'reversed_at' => 'datetime', 'subtotal' => 'decimal:4', 'discount_total' => 'decimal:4', 'tax_total' => 'decimal:4', 'total' => 'decimal:4'];
    }
}
