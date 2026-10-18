<?php

namespace Database\Factories;

use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ImportBatch>
 */
class ImportBatchFactory extends Factory
{
    protected $model = ImportBatch::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'guest_workspace_id' => GuestWorkspace::factory(),
            'report_type' => 'donations',
            'original_filename' => 'donations.csv',
            'private_file_path' => Str::uuid()->toString().'/'.Str::uuid()->toString().'.csv',
            'sha256_checksum' => hash('sha256', fake()->unique()->uuid()),
            'parsing_config' => null,
            'context' => null,
            'status' => ImportBatch::STATUS_UPLOADED,
            'total_rows' => 0,
            'processed_rows' => 0,
            'valid_rows' => 0,
            'review_rows' => 0,
            'rejected_rows' => 0,
            'materialized_rows' => 0,
            'duplicate_rows' => 0,
            'stale_rows' => 0,
            'conflicting_rows' => 0,
            'processing_error_code' => null,
            'completed_at' => null,
            'materialized_at' => null,
            'expires_at' => now()->addHour(),
        ];
    }

    public function mappingRequired(): static
    {
        return $this->state(fn () => [
            'status' => ImportBatch::STATUS_MAPPING_REQUIRED,
            'total_rows' => 3,
            'processed_rows' => 3,
            'completed_at' => now(),
            'parsing_config' => [
                'delimiter' => ',',
                'headers' => ['Gift Date', 'Gift Value'],
            ],
        ]);
    }

    public function failed(string $code = 'MALFORMED_CSV'): static
    {
        return $this->state(fn () => [
            'status' => ImportBatch::STATUS_FAILED,
            'processing_error_code' => $code,
            'completed_at' => now(),
        ]);
    }
}
