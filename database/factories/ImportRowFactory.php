<?php

namespace Database\Factories;

use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ImportRow>
 */
class ImportRowFactory extends Factory
{
    protected $model = ImportRow::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'import_batch_id' => ImportBatch::factory(),
            'row_number' => fake()->unique()->numberBetween(1, 10000),
            'raw_data' => [
                'Gift Date' => '2025-01-15',
                'Supporter' => fake()->name(),
                'Gift Value' => '25.00',
            ],
            'normalized_data' => null,
            'corrected_data' => null,
            'status' => ImportRow::STATUS_STAGED,
            'validation_issues' => null,
            'row_version' => 1,
        ];
    }
}
