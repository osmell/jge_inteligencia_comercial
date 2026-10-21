<?php

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Oportunidad de venta cruzada de un cliente: compra el antecedente de una regla y todavía no su consecuente.
 *
 * @property int $id
 * @property int $insight_run_id
 * @property int $rule_id
 * @property int $company_id
 * @property string $customer_code
 * @property string $customer_name
 */
class CrossSellOpportunity extends Model
{
    use ScopedByOrganization;

    public $timestamps = false;

    protected $guarded = [];

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    /** @return BelongsTo<CrossSellRule, $this> */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(CrossSellRule::class, 'rule_id');
    }

    /** Solo los clientes que el usuario puede ver (un vendedor, los suyos). */
    public function scopeVisibleCustomers(Builder $query): void
    {
        $query->whereExists(Customer::query()->selectRaw('1')->whereColumn('customers.company_id', 'cross_sell_opportunities.company_id')->whereColumn('customers.code', 'cross_sell_opportunities.customer_code')->toBase());
    }
}
