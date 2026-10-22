<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Segmento RFM de un cliente en el último cálculo de su compañía.
 *
 * @property int $id
 * @property int $insight_run_id
 * @property int $company_id
 * @property string $customer_code
 * @property string $customer_name
 * @property Carbon $first_purchase
 * @property Carbon $last_purchase
 * @property int $recency_days
 * @property int $frequency
 * @property string $gross
 * @property string $returns
 * @property string $monetary
 * @property int $r
 * @property int $f
 * @property int $m
 * @property string $segment
 */
class RfmScore extends Model
{
    use ScopedByOrganization;

    public $timestamps = false;

    protected $guarded = [];

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['first_purchase' => 'date:Y-m-d', 'last_purchase' => 'date:Y-m-d', 'gross' => 'decimal:4', 'returns' => 'decimal:4', 'monetary' => 'decimal:4'];
    }

    /** Solo los clientes que el usuario puede ver (un vendedor, los suyos): el alcance del modelo Customer. */
    public function scopeVisibleCustomers(Builder $query): void
    {
        $query->whereExists(Customer::query()->selectRaw('1')->whereColumn('customers.company_id', 'rfm_scores.company_id')->whereColumn('customers.code', 'rfm_scores.customer_code')->toBase());
    }
}
