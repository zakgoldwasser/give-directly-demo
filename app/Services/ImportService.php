<?php

namespace App\Services;

use App\Domain\Reconciliation\Exceptions\CsvParseException;
use App\Domain\Reconciliation\Services\CsvParserService;
use App\Domain\Reconciliation\Services\MappingValidationService;
use App\Domain\Reconciliation\Services\ReferenceFixtureCatalog;
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
        private readonly ReferenceFixtureCatalog $fixtures,
        private readonly MappingValidationService $mappings,
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
        $realPath = $file->getRealPath();

        if ($realPath === false || ! is_readable($realPath)) {
            throw new ImportLimitException('FILE_UNREADABLE', 'The uploaded file could not be read.');
        }

        $originalName = $file->getClientOriginalName() !== ''
            ? $file->getClientOriginalName()
            : $reportType.'.csv';

        return $this->createFromPath($workspace, $realPath, $reportType, $originalName, true);
    }

    /**
     * Load one versioned synthetic report from the server-side fixture catalog.
     *
     * @throws DuplicateImportException
     * @throws ImportLimitException
     */
    public function createFromFixture(GuestWorkspace $workspace, string $reportType): ImportBatch
    {
        $report = $this->fixtures->report($reportType);

        return $this->createFromPath(
            $workspace,
            $this->fixtures->path($reportType),
            $reportType,
            (string) $report['filename'],
            false,
        );
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

            $this->mappings->initialize($batch->refresh());
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
    private function assertUploadIsAllowed(string $checksum, string $reportType): void
    {
        if ((bool) config('reconciliation.allow_arbitrary_uploads')) {
            return;
        }

        if ($this->fixtures->isApproved($reportType, $checksum)) {
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

    /**
     * @throws DuplicateImportException
     * @throws ImportLimitException
     */
    private function createFromPath(
        GuestWorkspace $workspace,
        string $sourcePath,
        string $reportType,
        string $originalName,
        bool $enforceUploadPolicy,
    ): ImportBatch {
        $size = filesize($sourcePath);
        $maxBytes = (int) config('reconciliation.max_csv_size_bytes');

        if ($size === false) {
            throw new ImportLimitException('FILE_UNREADABLE', 'The CSV could not be read.');
        }

        if ($size > $maxBytes) {
            throw new ImportLimitException('FILE_TOO_LARGE', 'The CSV exceeds the maximum allowed size.');
        }

        $checksum = hash_file('sha256', $sourcePath);

        if ($checksum === false) {
            throw new ImportLimitException('FILE_UNREADABLE', 'The CSV could not be read.');
        }

        if ($enforceUploadPolicy) {
            $this->assertUploadIsAllowed($checksum, $reportType);
        }

        $existing = $this->findByChecksumAndType($workspace, $checksum, $reportType);

        if ($existing !== null) {
            throw new DuplicateImportException($existing);
        }

        $this->assertUnderActiveCap($workspace);

        $relativePath = $workspace->id.'/'.Str::uuid()->toString().'.csv';
        $stream = fopen($sourcePath, 'rb');

        if ($stream === false) {
            throw new ImportLimitException('FILE_UNREADABLE', 'The CSV could not be read.');
        }

        try {
            $stored = Storage::disk('imports')->put($relativePath, $stream);
        } finally {
            fclose($stream);
        }

        if (! $stored) {
            throw new ImportLimitException('FILE_STORAGE_FAILED', 'The CSV could not be stored.');
        }

        $batch = ImportBatch::query()->create([
            'guest_workspace_id' => $workspace->id,
            'report_type' => $reportType,
            'original_filename' => $originalName,
            'private_file_path' => $relativePath,
            'sha256_checksum' => $checksum,
            'parsing_config' => null,
            'context' => $this->fixtures->contextFor($reportType, $checksum),
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

    private function failBatch(ImportBatch $batch, string $errorCode): void
    {
        $batch->forceFill([
            'status' => ImportBatch::STATUS_FAILED,
            'processing_error_code' => $errorCode,
            'completed_at' => now(),
        ])->save();
    }
}
