<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Totales oficiales del sistema de origen por vendedor, acumulados del inicio del período hasta la fecha de corte.
 * Sirven para conciliar las ventas importadas; un archivo del mismo corte reemplaza al anterior.
 *
 * @property int $id
 * @property int $company_id
 * @property Carbon $period_from
 * @property Carbon $cutoff_on
 * @property string $seller_name
 * @property string $net_sale
 * @property int $invoices_count
 */
#[Fillable([
    'company_id', 'import_batch_id', 'period_from', 'cutoff_on', 'seller_name', 'normalized_name', 'seller_id',
    'gross_sale', 'discount', 'sale', 'returns', 'net_sale', 'tax', 'withholding', 'total', 'invoices_count',
])]
class SellerSalesSummary extends Model
{
    use ScopedByOrganization;

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['period_from' => 'date', 'cutoff_on' => 'date'];
    }

    /** @return BelongsTo<Seller, $this> */
    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }
}
