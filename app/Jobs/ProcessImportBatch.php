<?php

namespace App\Jobs;

use App\Domain\Donations\Exceptions\CsvParseException;
use App\Domain\Donations\Services\CsvParserService;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessImportBatch implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(
        public readonly string $importBatchId,
        public readonly int $processingVersion,
    ) {
        $this->timeout = (int) config('givingledger.max_processing_seconds', 60);
    }

    public function handle(CsvParserService $parser): void
    {
        $batch = ImportBatch::query()->find($this->importBatchId);

        if ($batch === null) {
            return;
        }

        if ($batch->processing_version !== $this->processingVersion) {
            return;
        }

        if (in_array($batch->status, [
            ImportBatch::STATUS_MAPPING_REQUIRED,
            ImportBatch::STATUS_COMPLETED,
            ImportBatch::STATUS_FAILED,
            ImportBatch::STATUS_EXPIRED,
        ], true)) {
            return;
        }

        $workspace = $batch->workspace;

        if ($workspace === null || $workspace->isExpired()) {
            $batch->forceFill([
                'status' => ImportBatch::STATUS_EXPIRED,
                'processing_error_code' => 'WORKSPACE_EXPIRED',
            ])->save();

            return;
        }

        $batch->forceFill([
            'status' => ImportBatch::STATUS_QUEUED,
        ])->save();

        $batch->forceFill([
            'status' => ImportBatch::STATUS_PROCESSING,
            'processing_error_code' => null,
        ])->save();

        $absolutePath = Storage::disk('imports')->path($batch->private_file_path);

        try {
            $parsed = $parser->parse($absolutePath);

            DB::transaction(function () use ($batch, $parsed): void {
                ImportRow::query()
                    ->where('import_batch_id', $batch->id)
                    ->delete();

                $now = now();
                $inserts = [];

                foreach ($parsed['rows'] as $index => $row) {
                    $inserts[] = [
                        'id' => (string) Str::uuid(),
                        'import_batch_id' => $batch->id,
                        'row_number' => $index + 1,
                        'raw_data' => json_encode($row, JSON_THROW_ON_ERROR),
                        'normalized_data' => null,
                        'corrected_data' => null,
                        'status' => ImportRow::STATUS_STAGED,
                        'validation_issues' => null,
                        'row_version' => 1,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($inserts, 100) as $chunk) {
                    ImportRow::query()->insert($chunk);
                }

                $batch->forceFill([
                    'status' => ImportBatch::STATUS_MAPPING_REQUIRED,
                    'total_rows' => $parsed['total_rows'],
                    'processed_rows' => $parsed['total_rows'],
                    'parsing_config' => [
                        'delimiter' => $parsed['delimiter'],
                        'headers' => $parsed['headers'],
                    ],
                    'processing_error_code' => null,
                    'completed_at' => $now,
                ])->save();
            });
        } catch (CsvParseException $exception) {
            $this->failBatch($batch, $exception->errorCode);
            Log::warning('Import parse failed', [
                'import_batch_id' => $batch->id,
                'error_code' => $exception->errorCode,
            ]);
        } catch (Throwable $exception) {
            Log::error('Import processing failed', [
                'import_batch_id' => $batch->id,
                'error_code' => 'PROCESSING_FAILED',
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    public function failed(?Throwable $exception): void
    {
        $batch = ImportBatch::query()->find($this->importBatchId);

        if ($batch === null || $batch->processing_version !== $this->processingVersion) {
            return;
        }

        if ($batch->status === ImportBatch::STATUS_MAPPING_REQUIRED) {
            return;
        }

        $this->failBatch($batch, 'PROCESSING_FAILED');
    }

    private function failBatch(ImportBatch $batch, string $errorCode): void
    {
        $batch->forceFill([
            'status' => ImportBatch::STATUS_FAILED,
            'processing_error_code' => $errorCode,
            'completed_at' => now(),
        ])->save();
    }
}
