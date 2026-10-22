<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\SellerGoals;
use PHPUnit\Framework\TestCase;

final class SellerGoalsMonthTest extends TestCase
{
    public function test_month_start_is_the_first_day_at_midnight(): void
    {
        $this->assertSame('2026-03-01 00:00:00', SellerGoals::monthStart('2026-03')->format('Y-m-d H:i:s'));
        $this->assertSame('2028-02-01', SellerGoals::monthStart('2028-02')->toDateString());
    }
}
