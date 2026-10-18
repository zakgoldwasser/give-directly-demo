<?php

namespace App\Services;

use App\Jobs\ProcessImportBatch;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Services\ImportExceptions\DuplicateImportException;
use App\Services\ImportExceptions\ImportLimitException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ImportService
{
    /**
     * Create an import from the built-in sample fixture.
     *
     * @throws ImportLimitException
     * @throws DuplicateImportException
     */
    public function createFromSample(GuestWorkspace $workspace): ImportBatch
    {
        $this->assertUnderActiveCap($workspace);

        $fixturePath = resource_path('fixtures/sample-donations.csv');
        $mappingPath = resource_path('fixtures/sample-mapping.json');

        if (! is_readable($fixturePath)) {
            throw new ImportLimitException('SAMPLE_MISSING', 'The sample dataset is unavailable.');
        }

        $contents = file_get_contents($fixturePath);

        if ($contents === false) {
            throw new ImportLimitException('SAMPLE_MISSING', 'The sample dataset is unavailable.');
        }

        $checksum = hash('sha256', $contents);
        $existing = $this->findByChecksum($workspace, $checksum);

        if ($existing !== null) {
            throw new DuplicateImportException($existing);
        }

        $mappingConfig = null;

        if (is_readable($mappingPath)) {
            $decoded = json_decode((string) file_get_contents($mappingPath), true);
            $mappingConfig = is_array($decoded) ? $decoded : null;
        }

        $relativePath = $this->storeContents($workspace, $contents, 'sample-donations.csv');

        $batch = $this->createBatch(
            workspace: $workspace,
            originalFilename: 'sample-donations.csv',
            privateFilePath: $relativePath,
            checksum: $checksum,
            mappingConfig: $mappingConfig,
            sourcePartner: 'GivingLedger Sample',
        );

        ProcessImportBatch::dispatch($batch->id, $batch->processing_version);

        return $batch;
    }

    /**
     * Create an import from an uploaded CSV.
     *
     * @throws ImportLimitException
     * @throws DuplicateImportException
     */
    public function createFromUpload(GuestWorkspace $workspace, UploadedFile $file): ImportBatch
    {
        $this->assertUnderActiveCap($workspace);

        $maxBytes = (int) config('givingledger.max_csv_size_bytes');

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

        $existing = $this->findByChecksum($workspace, $checksum);

        if ($existing !== null) {
            throw new DuplicateImportException($existing);
        }

        $originalName = $file->getClientOriginalName() !== ''
            ? $file->getClientOriginalName()
            : 'upload.csv';

        $relativePath = $workspace->id.'/'.Str::uuid()->toString().'.csv';
        Storage::disk('imports')->put($relativePath, fopen($realPath, 'rb'));

        $batch = $this->createBatch(
            workspace: $workspace,
            originalFilename: $originalName,
            privateFilePath: $relativePath,
            checksum: $checksum,
            mappingConfig: null,
            sourcePartner: null,
        );

        ProcessImportBatch::dispatch($batch->id, $batch->processing_version);

        return $batch;
    }

    public function findForWorkspace(GuestWorkspace $workspace, string $importId): ?ImportBatch
    {
        return ImportBatch::query()
            ->where('guest_workspace_id', $workspace->id)
            ->whereKey($importId)
            ->first();
    }

    /**
     * @throws ImportLimitException
     */
    private function assertUnderActiveCap(GuestWorkspace $workspace): void
    {
        $max = (int) config('givingledger.max_active_imports_per_session');

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

    private function findByChecksum(GuestWorkspace $workspace, string $checksum): ?ImportBatch
    {
        return ImportBatch::query()
            ->where('guest_workspace_id', $workspace->id)
            ->where('sha256_checksum', $checksum)
            ->whereIn('status', ImportBatch::ACTIVE_STATUSES)
            ->latest()
            ->first();
    }

    private function storeContents(GuestWorkspace $workspace, string $contents, string $label): string
    {
        $relativePath = $workspace->id.'/'.Str::uuid()->toString().'.csv';
        Storage::disk('imports')->put($relativePath, $contents);

        return $relativePath;
    }

    /**
     * @param  array<string, mixed>|null  $mappingConfig
     */
    private function createBatch(
        GuestWorkspace $workspace,
        string $originalFilename,
        string $privateFilePath,
        string $checksum,
        ?array $mappingConfig,
        ?string $sourcePartner,
    ): ImportBatch {
        return ImportBatch::query()->create([
            'guest_workspace_id' => $workspace->id,
            'original_filename' => $originalFilename,
            'private_file_path' => $privateFilePath,
            'sha256_checksum' => $checksum,
            'source_partner' => $sourcePartner,
            'mapping_config' => $mappingConfig,
            'parsing_config' => null,
            'status' => ImportBatch::STATUS_UPLOADED,
            'total_rows' => 0,
            'processed_rows' => 0,
            'valid_rows' => 0,
            'review_rows' => 0,
            'rejected_rows' => 0,
            'processing_version' => 1,
            'processing_error_code' => null,
            'completed_at' => null,
            'expires_at' => $workspace->expires_at,
        ]);
    }
}
