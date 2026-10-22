<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Models\Quotation;
use App\Models\User;
use App\Services\FollowUpScope;
use Carbon\CarbonImmutable;

/** Sección «quotations» del dashboard inicial. */
final class QuotationsSection extends DashboardSection
{
    public function __construct(private readonly FollowUpScope $followScope) {}

    public function key(): string
    {
        return 'quotations';
    }

    public function permission(): string
    {
        return 'customers.view';
    }

    /** @return array<string, mixed> */
    public function build(DashboardContext $context): array
    {
        return $this->summary($context->user, $context->window, $context->companyId, $context->today);
    }

    /**
     * @param  array<string, mixed>  $w
     * @return array<string, mixed>
     */
    private function summary(User $user, array $w, ?int $companyId, CarbonImmutable $today): array
    {
        $query = fn () => tap(Quotation::query()->when($companyId !== null, fn ($q) => $q->where('quotations.company_id', $companyId)), fn ($q) => $this->followScope->restrict($q, $user, []));
        $open = $query()->whereIn('status', Quotation::EXPIRABLE)->get(['status', 'amount', 'valid_until']);
        $alive = $open->filter(fn (Quotation $q) => $q->valid_until === null || $q->valid_until->gte($today));
        $soon = $alive->filter(fn (Quotation $q) => $q->valid_until !== null && $q->valid_until->lte($today->addDays(3)));
        $range = [$w['from']->startOfDay(), $w['to']->endOfDay()];
        $issued = $query()->whereBetween('issued_at', $range)->get(['amount']);
        $converted = $query()->whereBetween('converted_at', $range)->get(['amount']);

        return [
            'open' => ['count' => $alive->count(), 'amount' => number_format((float) $alive->sum('amount'), 2, '.', '')],
            'expiring' => ['count' => $soon->count(), 'amount' => number_format((float) $soon->sum('amount'), 2, '.', '')],
            'expired_unclosed' => $open->count() - $alive->count(),
            'issued' => ['count' => $issued->count(), 'amount' => number_format((float) $issued->sum('amount'), 2, '.', '')],
            'converted' => ['count' => $converted->count(), 'amount' => number_format((float) $converted->sum('amount'), 2, '.', '')],
        ];
    }
}
