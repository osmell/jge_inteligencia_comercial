<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Services\Reports\ReportPeriod;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ReportPeriodTest extends TestCase
{
    private function d(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date);
    }

    public function test_the_previous_period_is_the_days_right_before_with_the_same_length(): void
    {
        $period = ReportPeriod::resolve($this->d('2026-10-01'), $this->d('2026-10-31'));

        $this->assertSame('2026-08-31', $period->compareFrom->toDateString());
        $this->assertSame('2026-09-30', $period->compareTo->toDateString());
        $this->assertSame(31, $period->days());
        $this->assertSame(31, $period->compareDays());
        $this->assertFalse($period->isUnevenComparison());
    }

    public function test_the_same_dates_of_last_year(): void
    {
        $period = ReportPeriod::resolve($this->d('2026-10-01'), $this->d('2026-10-15'), ReportPeriod::LAST_YEAR);

        $this->assertSame(['2025-10-01', '2025-10-15'], [$period->compareFrom->toDateString(), $period->compareTo->toDateString()]);
    }

    public function test_last_year_does_not_overflow_from_a_leap_day(): void
    {
        $period = ReportPeriod::resolve($this->d('2028-02-29'), $this->d('2028-02-29'), ReportPeriod::LAST_YEAR);

        $this->assertSame('2027-02-28', $period->compareFrom->toDateString());
    }

    public function test_a_custom_comparison_that_lasts_a_different_time_is_flagged_as_uneven(): void
    {
        $period = ReportPeriod::resolve($this->d('2026-10-01'), $this->d('2026-10-31'), ReportPeriod::CUSTOM, $this->d('2026-02-01'), $this->d('2026-02-28'));

        $this->assertTrue($period->isUnevenComparison());
        $this->assertSame(true, $period->toArray()['comparison']['uneven']);
    }

    public function test_a_single_day_and_a_whole_year_work(): void
    {
        $this->assertSame(1, ReportPeriod::resolve($this->d('2026-10-05'), $this->d('2026-10-05'))->days());
        $this->assertSame(365, ReportPeriod::resolve($this->d('2026-01-01'), $this->d('2026-12-31'))->days());
    }

    public function test_it_rejects_inverted_ranges_unknown_modes_and_incomplete_custom_ones(): void
    {
        foreach ([
            fn () => ReportPeriod::resolve($this->d('2026-10-31'), $this->d('2026-10-01')),
            fn () => ReportPeriod::resolve($this->d('2026-10-01'), $this->d('2026-10-31'), 'otro'),
            fn () => ReportPeriod::resolve($this->d('2026-10-01'), $this->d('2026-10-31'), ReportPeriod::CUSTOM),
            fn () => ReportPeriod::resolve($this->d('2026-10-01'), $this->d('2026-10-31'), ReportPeriod::CUSTOM, $this->d('2026-09-30'), $this->d('2026-09-01')),
        ] as $call) {
            try {
                $call();
                $this->fail('Debió rechazar el periodo.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
