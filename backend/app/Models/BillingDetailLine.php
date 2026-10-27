<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Línea del «Detalle de facturación» del sistema de origen (precio establecido en la factura).
 * Referencia para corregir las equivalencias; el detalle de un documento se reemplaza cuando llega distinto en una carga posterior.
 *
 * @property int $id
 * @property int $company_id
 * @property string $document
 * @property string $series
 * @property Carbon $issued_on
 * @property string $units
 * @property string $price
 * @property string $sale_total
 */
#[Fillable([
    'company_id', 'import_batch_id', 'document', 'line_number', 'series', 'issued_on', 'customer_name', 'product_code', 'product_name',
    'units', 'unit_cost', 'price', 'sale_total', 'cost_total', 'profit_total', 'content_hash',
])]
class BillingDetailLine extends Model
{
    use ScopedByOrganization;

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['issued_on' => 'date'];
    }
}
