<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\ScopedByOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Alerta de un cliente: una recomendación con los datos y la regla que la generaron.
 *
 * @property int $id
 * @property int $company_id
 * @property int $insight_run_id
 * @property string $customer_code
 * @property string $customer_name
 * @property string $type
 * @property string $severity
 * @property string $dedupe_key
 * @property array<string, mixed> $facts
 * @property string $explanation
 * @property string $status
 * @property Carbon $first_detected_at
 * @property Carbon $last_detected_at
 * @property Carbon|null $resolved_at
 * @property Carbon|null $closed_at
 * @property string|null $close_reason
 * @property string|null $close_note
 * @property int|null $closed_by
 * @property int|null $follow_up_interaction_id
 */
class InsightAlert extends Model
{
    use ScopedByOrganization;

    public const INACTIVITY_RISK = 'inactivity_risk';

    public const SALES_DROP = 'sales_drop';

    public const SALES_RISE = 'sales_rise';

    public const ABANDONED_LINE = 'abandoned_line';

    public const CROSS_SELL = 'cross_sell';

    public const RFM_AT_RISK = 'rfm_at_risk';

    public const TYPES = [self::INACTIVITY_RISK, self::SALES_DROP, self::SALES_RISE, self::ABANDONED_LINE, self::CROSS_SELL, self::RFM_AT_RISK];

    /** Motivos para cerrar una alerta; con «other» la nota es obligatoria. */
    public const CLOSE_REASONS = ['contacted', 'not_applicable', 'do_not_contact', 'resolved', 'other'];

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const CLOSED = 'closed';

    public $timestamps = false;

    protected $guarded = [];

    public static function organizationBranchColumn(): ?string
    {
        return null;
    }

    protected function casts(): array
    {
        return ['facts' => 'array', 'first_detected_at' => 'datetime', 'last_detected_at' => 'datetime', 'resolved_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    /**
     * Solo los clientes que el usuario puede ver (un vendedor, los suyos).
     *
     * @param  Builder<InsightAlert>  $query
     */
    public function scopeVisibleCustomers(Builder $query): void
    {
        $query->whereExists(Customer::query()->selectRaw('1')->whereColumn('customers.company_id', 'insight_alerts.company_id')->whereColumn('customers.code', 'insight_alerts.customer_code')->toBase());
    }
}
