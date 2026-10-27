<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Company;
use App\Models\SalesLine;
use App\Models\SalesLineCorrection;
use App\Services\Sales\NetCorrection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\BuildsSalesFixtures;
use Tests\TestCase;

class NetCorrectionTest extends TestCase
{
    use BuildsSalesFixtures, RefreshDatabase;

    public function test_only_fractional_lines_with_a_real_price_are_corrected_and_the_originals_stay(): void
    {
        $company = Company::factory()->create();
        $branch = Branch::factory()->create(['company_id' => $company->id]);
        $this->sale($this->seller($company, 'A', $branch), $this->operator($company, 'O'), $branch, '2026-08-03', 'C1', [
            ['qty' => 0.25, 'price' => 112.5, 'net' => 28.125],   // porción: 1/4 de galón, el reporte multiplicó otra vez
            ['qty' => 2, 'price' => 100, 'net' => 200],            // entera: no se toca
            ['qty' => 0.5, 'price' => 0.0087, 'net' => 0.0044],    // componente de precio simbólico: no se toca
        ]);

        $this->assertSame(1, app(NetCorrection::class)->run());

        $fix = SalesLineCorrection::sole();
        $this->assertSame(SalesLineCorrection::PORTION, $fix->cause);
        $this->assertEquals(112.5, $fix->corrected_net);
        $this->assertEquals(11.25, $fix->corrected_tax); // misma tasa que la línea (10 % en el ejemplo)
        $this->assertEquals(28.125, SalesLine::where('units', 0.25)->sole()->net_total);

        $this->assertSame(0, app(NetCorrection::class)->run(), 'Es idempotente.');
    }
}
