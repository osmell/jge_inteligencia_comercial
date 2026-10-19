<?php

namespace Database\Factories;

use App\Models\Branch;
use App\Models\ImportBatch;
use App\Models\Operator;
use App\Models\SalesDocument;
use App\Models\Seller;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesDocument>
 */
class SalesDocumentFactory extends Factory
{
    public function definition(): array
    {
        $branch = Branch::factory()->create();

        return [
            'company_id' => $branch->company_id,
            'branch_id' => $branch->id,
            'series' => 'A',
            'number' => (string) fake()->unique()->numberBetween(1, 999999),
            'issued_on' => '2026-10-01',
            'customer_code' => 'C'.fake()->numberBetween(100, 999),
            'customer_name' => fake()->company(),
            'seller_id' => fn (array $a) => Seller::firstOrCreate(['company_id' => $a['company_id'], 'normalized_name' => 'vendedor demo'], ['name' => 'Vendedor Demo'])->id,
            'operator_id' => fn (array $a) => Operator::firstOrCreate(['company_id' => $a['company_id'], 'normalized_name' => 'operador demo'], ['name' => 'Operador Demo'])->id,
            'import_batch_id' => ImportBatch::factory(),
            'lines_count' => 1,
            'content_hash' => hash('sha256', fake()->uuid()),
        ];
    }
}
