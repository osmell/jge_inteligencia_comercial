<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\InsightAlert;
use Illuminate\Support\Facades\DB;

/** Sección «insights» del dashboard inicial. */
final class InsightsSection extends DashboardSection
{
    public function key(): string
    {
        return 'insights';
    }

    public function permission(): string
    {
        return 'insights.view';
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->alerts($context->sellerIds, $context->companyId);
    }

    /**
     * @param  list<int>|null  $sellerIds
     * @return array<string, mixed>
     */
    private function alerts(?array $sellerIds, ?int $companyId): array
    {
        $rows = InsightAlert::query()->where('status', InsightAlert::OPEN)
            ->when($companyId !== null, fn ($q) => $q->where('insight_alerts.company_id', $companyId))
            ->when($sellerIds !== null, fn ($q) => $q->whereExists(DB::table('customers')->selectRaw('1')->whereColumn('customers.company_id', 'insight_alerts.company_id')->whereColumn('customers.code', 'insight_alerts.customer_code')->whereIn('customers.seller_id', $sellerIds)))
            ->get(['type', 'severity']);

        return [
            'open' => $rows->count(), 'by_severity' => ['high' => $rows->where('severity', 'high')->count(), 'medium' => $rows->where('severity', 'medium')->count(), 'low' => $rows->where('severity', 'low')->count()],
            'at_risk' => $rows->where('type', InsightAlert::RFM_AT_RISK)->count() + $rows->where('type', InsightAlert::INACTIVITY_RISK)->count(), 'sales_drop' => $rows->where('type', InsightAlert::SALES_DROP)->count(),
        ];
    }
}
