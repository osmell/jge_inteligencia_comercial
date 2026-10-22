<?php

declare(strict_types=1);

namespace Tests\Unit\Services;

use App\Services\CreditAging;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class CreditAgingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['credit.aging_limits' => [30, 60, 90, 120]]);
    }

    /** @return array<string, array{int, string}> */
    public static function ages(): array
    {
        return [
            'negativo' => [-5, 'current'],
            'cero' => [0, 'current'],
            'primer día' => [1, '1-30'],
            'límite superior del primer tramo' => [30, '1-30'],
            'primer día del segundo' => [31, '31-60'],
            'tercer tramo' => [90, '61-90'],
            'cuarto tramo' => [120, '91-120'],
            'más allá del último límite' => [121, '121+'],
            'muy antigua' => [9999, '121+'],
        ];
    }

    #[DataProvider('ages')]
    public function test_age_falls_in_the_right_bucket(int $age, string $bucket): void
    {
        $this->assertSame($bucket, CreditAging::bucket($age));
    }

    public function test_bucket_keys_follow_the_limits(): void
    {
        $this->assertSame(['current', '1-30', '31-60', '61-90', '91-120', '121+'], CreditAging::buckets());
    }

    public function test_limits_are_sorted_and_deduplicated(): void
    {
        config(['credit.aging_limits' => ['90', 30, 30, 60]]);

        $this->assertSame([30, 60, 90], CreditAging::limits());
        $this->assertSame(['current', '1-30', '31-60', '61-90', '91+'], CreditAging::buckets());
        $this->assertSame('91+', CreditAging::bucket(100));
    }
}
