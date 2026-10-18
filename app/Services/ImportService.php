<?php

namespace App\Services;

use App\Domain\Reconciliation\Exceptions\CsvParseException;
use App\Domain\Reconciliation\Services\CsvParserService;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Services\ImportExceptions\DuplicateImportException;
use App\Services\ImportExceptions\ImportLimitException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ImportService
{
    public function __construct(
        private readonly CsvParserService $parser,
    ) {}

    /**
     * Stage and parse one reconciliation source report synchronously.
     *
     * @throws DuplicateImportException
     * @throws ImportLimitException
     */
    public function createFromUpload(
        GuestWorkspace $workspace,
        UploadedFile $file,
        string $reportType,
    ): ImportBatch {
        $this->assertUnderActiveCap($workspace);

        $maxBytes = (int) config('reconciliation.max_csv_size_bytes');

        if ($file->getSize() !== false && $file->getSize() > $maxBytes) {
            throw new ImportLimitException('FILE_TOO_LARGE', 'The CSV exceeds the maximum allowed size.');
        }

        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_readable($realPath)) {
            throw new ImportLimitException('FILE_UNREADABLE', 'The uploaded file could not be read.');
        }

        $checksum = hash_file('sha256', $realPath);

        if ($checksum === false) {
            throw new ImportLimitException('FILE_UNREADABLE', 'The uploaded file could not be read.');
        }

        $this->assertUploadIsAllowed($checksum);

        $existing = $this->findByChecksumAndType($workspace, $checksum, $reportType);

        if ($existing !== null) {
            throw new DuplicateImportException($existing);
        }

        $originalName = $file->getClientOriginalName() !== ''
            ? $file->getClientOriginalName()
            : $reportType.'.csv';
        $relativePath = $workspace->id.'/'.Str::uuid()->toString().'.csv';

        Storage::disk('imports')->put($relativePath, fopen($realPath, 'rb'));

        $batch = ImportBatch::query()->create([
            'guest_workspace_id' => $workspace->id,
            'report_type' => $reportType,
            'original_filename' => $originalName,
            'private_file_path' => $relativePath,
            'sha256_checksum' => $checksum,
            'parsing_config' => null,
            'status' => ImportBatch::STATUS_UPLOADED,
            'total_rows' => 0,
            'processed_rows' => 0,
            'valid_rows' => 0,
            'review_rows' => 0,
            'rejected_rows' => 0,
            'processing_error_code' => null,
            'completed_at' => null,
            'expires_at' => $workspace->expires_at,
        ]);

        $this->process($batch);

        return $batch->refresh();
    }

    public function findForWorkspace(GuestWorkspace $workspace, string $importId): ?ImportBatch
    {
        return ImportBatch::query()
            ->where('guest_workspace_id', $workspace->id)
            ->whereKey($importId)
            ->first();
    }

    private function process(ImportBatch $batch): void
    {
        $batch->forceFill([
            'status' => ImportBatch::STATUS_PROCESSING,
            'processing_error_code' => null,
        ])->save();

        try {
            $parsed = $this->parser->parse(Storage::disk('imports')->path($batch->private_file_path));

            DB::transaction(function () use ($batch, $parsed): void {
                $now = now();
                $rows = [];

                foreach ($parsed['rows'] as $index => $row) {
                    $rows[] = [
                        'id' => (string) Str::uuid(),
                        'import_batch_id' => $batch->id,
                        'row_number' => $index + 1,
                        'raw_data' => json_encode($row, JSON_THROW_ON_ERROR),
                        'status' => ImportRow::STATUS_STAGED,
                        'validation_issues' => null,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                foreach (array_chunk($rows, 100) as $chunk) {
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
            $this->failBatch($batch, 'PROCESSING_FAILED');
            Log::error('Import processing failed', [
                'import_batch_id' => $batch->id,
                'error_code' => 'PROCESSING_FAILED',
                'exception' => $exception::class,
            ]);

            throw $exception;
        }
    }

    /**
     * @throws ImportLimitException
     */
    private function assertUnderActiveCap(GuestWorkspace $workspace): void
    {
        $max = (int) config('reconciliation.max_active_imports_per_session');
        $active = ImportBatch::query()
            ->where('guest_workspace_id', $workspace->id)
            ->whereIn('status', ImportBatch::ACTIVE_STATUSES)
            ->count();

        if ($active >= $max) {
            throw new ImportLimitException(
                'IMPORT_LIMIT_REACHED',
                "This workspace may have at most {$max} active imports."
            );
        }
    }

    /**
     * @throws ImportLimitException
     */
    private function assertUploadIsAllowed(string $checksum): void
    {
        if ((bool) config('reconciliation.allow_arbitrary_uploads')) {
            return;
        }

        $approved = config('reconciliation.approved_fixture_checksums', []);

        if (is_array($approved) && in_array($checksum, $approved, true)) {
            return;
        }

        throw new ImportLimitException(
            'PUBLIC_FIXTURE_REQUIRED',
            'Use one of the supplied synthetic sample files. Arbitrary CSV uploads are available only in local evaluation mode.'
        );
    }

    private function findByChecksumAndType(
        GuestWorkspace $workspace,
        string $checksum,
        string $reportType,
    ): ?ImportBatch {
        return ImportBatch::query()
            ->where('guest_workspace_id', $workspace->id)
            ->where('report_type', $reportType)
            ->where('sha256_checksum', $checksum)
            ->whereIn('status', ImportBatch::ACTIVE_STATUSES)
            ->latest()
            ->first();
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
