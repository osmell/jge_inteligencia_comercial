<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\ImportStatus;
use App\Models\ImportBatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'type' => 'sales',
            'status' => ImportStatus::Validated,
            'original_name' => 'ventas.xlsx',
            'stored_path' => 'sales/'.fake()->uuid().'.xlsx',
            'file_hash' => hash('sha256', fake()->uuid()),
            'file_size' => 1024,
            'user_id' => User::factory(),
        ];
    }

    public function status(ImportStatus $status): static
    {
        return $this->state(fn () => ['status' => $status]);
    }
}
