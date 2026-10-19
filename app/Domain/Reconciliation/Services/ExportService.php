<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\ExportBatch;
use App\Models\GiftReconciliation;
use App\Models\GuestWorkspace;
use App\Models\ReconciliationApproval;
use App\Models\ReconciliationRun;
use App\Services\Operations\ProcessingDeadline;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

class ExportService
{
    public const PROFILE_VERSION = 'generic_gift_v1';

    public function __construct(
        private readonly ProcessingDeadline $deadline,
    ) {}

    public function prepare(GuestWorkspace $workspace, string $runId): ExportBatch
    {
        $this->deadline->checkpoint();

        return DB::transaction(function () use ($workspace, $runId): ExportBatch {
            GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $run = $workspace->reconciliationRuns()
                ->where('status', ReconciliationRun::STATUS_COMPLETED)
                ->orderByDesc('sequence')
                ->first();

            if ($run === null || $run->id !== $runId) {
                throw ValidationException::withMessages([
                    'run_id' => 'The reconciliation changed. Approve and export the current run.',
                ]);
            }

            $results = $run->gifts()
                ->with(['gift.activeVersion.sourceImportBatch', 'gift.activeVersion.sourceImportRow'])
                ->orderBy('transaction_id')
                ->get();
            $eligible = $results->whereIn('outcome', [
                GiftReconciliation::OUTCOME_CREATE,
                GiftReconciliation::OUTCOME_UPDATE,
            ])->filter(fn (GiftReconciliation $result): bool => $result->issue_codes === [])->values();
            $approvals = $run->approvals()->orderBy('transaction_id')->get()->keyBy('gift_reconciliation_id');

            if ($eligible->isEmpty() || $eligible->contains(fn (GiftReconciliation $result): bool => ! $approvals->has($result->id))) {
                throw ValidationException::withMessages([
                    'export' => 'Approve every eligible create and update in the current run before exporting.',
                ]);
            }

            $fingerprint = $this->fingerprint($workspace, $run, $eligible, $approvals);
            $existing = $workspace->exportBatches()->where('fingerprint', $fingerprint)->first();

            if ($existing !== null) {
                return $existing;
            }

            $batchId = (string) Str::uuid();
            $generatedAt = now()->utc();
            $files = $this->files($workspace, $run, $results, $approvals);
            $this->deadline->checkpoint();
            $fileChecksums = collect($files)
                ->map(fn (string $contents): string => hash('sha256', $contents))
                ->all();
            $manifest = $this->manifest(
                $batchId,
                $fingerprint,
                $run,
                $results,
                $eligible,
                $approvals,
                $fileChecksums,
                $generatedAt->toIso8601String(),
            );
            $files['manifest.json'] = json_encode(
                $manifest,
                JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            )."\n";
            $zipBytes = $this->zip($files);
            $this->deadline->checkpoint();
            $privatePath = "{$workspace->id}/{$batchId}.zip";

            if (! Storage::disk('exports')->put($privatePath, $zipBytes)) {
                throw new RuntimeException('The private export artifact could not be stored.');
            }

            $batch = new ExportBatch([
                'guest_workspace_id' => $workspace->id,
                'reconciliation_run_id' => $run->id,
                'fingerprint' => $fingerprint,
                'status' => ExportBatch::STATUS_PREPARED,
                'profile_version' => self::PROFILE_VERSION,
                'counts' => $manifest['counts'],
                'file_checksums' => $fileChecksums,
                'manifest' => $manifest,
                'private_file_path' => $privatePath,
                'byte_size' => strlen($zipBytes),
                'generated_at' => $generatedAt,
            ]);
            $batch->id = $batchId;

            try {
                $this->deadline->checkpoint();
                $batch->save();
                $this->deadline->checkpoint();
            } catch (Throwable $exception) {
                Storage::disk('exports')->delete($privatePath);

                throw $exception;
            }

            return $batch;
        });
    }

    /**
     * @param  Collection<int, GiftReconciliation>  $eligible
     * @param  Collection<string, ReconciliationApproval>  $approvals
     */
    private function fingerprint(GuestWorkspace $workspace, ReconciliationRun $run, Collection $eligible, Collection $approvals): string
    {
        $actions = $eligible->map(fn (GiftReconciliation $result): array => [
            'approval_id' => $approvals->get($result->id)->id,
            'gift_reconciliation_id' => $result->id,
            'transaction_id' => $result->transaction_id,
            'action' => $result->outcome,
            'revision' => $approvals->get($result->id)->revision,
        ])->values()->all();

        return hash('sha256', json_encode([
            'workspace_id' => $workspace->id,
            'run_id' => $run->id,
            'run_fingerprint' => $run->input_fingerprint,
            'profile_version' => self::PROFILE_VERSION,
            'actions' => $actions,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  Collection<int, GiftReconciliation>  $results
     * @param  Collection<string, ReconciliationApproval>  $approvals
     * @return array<string, string>
     */
    private function files(GuestWorkspace $workspace, ReconciliationRun $run, Collection $results, Collection $approvals): array
    {
        $creates = [];
        $updates = [];
        $held = [];

        foreach ($results as $result) {
            $expected = $result->expected_values;

            if ($result->outcome === GiftReconciliation::OUTCOME_CREATE && $approvals->has($result->id)) {
                $creates[] = $this->actionValues($expected);
            }

            if ($result->outcome === GiftReconciliation::OUTCOME_UPDATE && $approvals->has($result->id)) {
                $updates[] = [
                    $this->requiredIdentifier($result->destination_values['crm_record_id'] ?? null, 'CRM record ID'),
                    ...$this->actionValues($expected),
                ];
            }

            if ($result->outcome === GiftReconciliation::OUTCOME_HELD) {
                $version = $result->gift?->activeVersion;
                $source = $version?->sourceImportBatch;
                $sourceRow = $version?->sourceImportRow;
                $held[] = [
                    (string) ($expected['external_gift_key'] ?? ''),
                    $result->transaction_id,
                    implode(';', $result->issue_codes),
                    'open',
                    implode(';', $result->issue_codes),
                    $source === null || $sourceRow === null
                        ? ''
                        : $source->original_filename.':row '.$sourceRow->row_number,
                ];
            }
        }

        return [
            'creates.csv' => $this->csv([
                'external_gift_key',
                'source_transaction_id',
                'gifted_at',
                'currency',
                'gross_amount',
                'refunded_amount',
                'gift_status',
                'campaign_id',
            ], $creates),
            'updates.csv' => $this->csv([
                'crm_record_id',
                'external_gift_key',
                'source_transaction_id',
                'gifted_at',
                'currency',
                'gross_amount',
                'refunded_amount',
                'gift_status',
                'campaign_id',
            ], $updates),
            'held.csv' => $this->csv([
                'external_gift_key',
                'transaction_id',
                'issue_codes',
                'review_state',
                'reason',
                'source_references',
            ], $held, true),
            'audit.csv' => $this->auditCsv($workspace, $run, $approvals),
        ];
    }

    /** @param array<string, mixed> $expected */
    private function actionValues(array $expected): array
    {
        return [
            $this->requiredIdentifier($expected['external_gift_key'] ?? null, 'External gift key'),
            $this->requiredIdentifier($expected['provider_transaction_id'] ?? null, 'Source transaction ID'),
            $this->requiredText($expected['gifted_at'] ?? null, 'Gift date'),
            $this->requiredText($expected['currency'] ?? null, 'Currency'),
            $this->minorToDecimal($expected['gross_amount_minor'] ?? null, 'Gross amount'),
            $this->minorToDecimal($expected['refunded_amount_minor'] ?? null, 'Refunded amount'),
            $this->requiredText($expected['gift_status'] ?? null, 'Gift status'),
            $this->requiredIdentifier($expected['campaign_id'] ?? null, 'Campaign ID'),
        ];
    }

    /** @param Collection<string, ReconciliationApproval> $approvals */
    private function auditCsv(GuestWorkspace $workspace, ReconciliationRun $run, Collection $approvals): string
    {
        $rows = [];

        foreach ($workspace->reviewDecisions()->orderBy('sequence')->get() as $decision) {
            $rows[] = [
                $decision->id,
                $decision->decision_type,
                $decision->actor_type,
                $decision->created_at->utc()->toIso8601String(),
                $decision->evidence['based_on_reconciliation_run_id'] ?? $run->id,
                $decision->subject_key,
                $decision->selected_value,
                '',
                $this->json($decision->evidence),
                '',
            ];
        }

        foreach ($approvals->sortBy('transaction_id') as $approval) {
            $rows[] = [
                $approval->id,
                'action_approval',
                $approval->actor_type,
                $approval->approved_at->utc()->toIso8601String(),
                $run->id,
                $approval->transaction_id,
                $approval->action,
                $this->json($approval->before_values),
                $this->json($approval->after_values),
                '',
            ];
        }

        return $this->csv([
            'event_id',
            'event_type',
            'actor_type',
            'occurred_at',
            'run_id',
            'subject_key',
            'selected_value',
            'before_values',
            'after_values',
            'reason',
        ], $rows, true);
    }

    /**
     * @param  Collection<int, GiftReconciliation>  $results
     * @param  Collection<int, GiftReconciliation>  $eligible
     * @param  Collection<string, ReconciliationApproval>  $approvals
     * @param  array<string, string>  $fileChecksums
     * @return array<string, mixed>
     */
    private function manifest(
        string $batchId,
        string $fingerprint,
        ReconciliationRun $run,
        Collection $results,
        Collection $eligible,
        Collection $approvals,
        array $fileChecksums,
        string $generatedAt,
    ): array {
        $imports = collect($run->input_snapshot['imports'] ?? []);
        $sourceChecksums = $imports->mapWithKeys(fn (array $import): array => [
            $import['report_type'] => $import['checksum'],
        ])->sortKeys()->all();
        $mappingProfiles = $imports->pluck('mapping_profile_id')->filter()->sort()->values()->all();
        $updates = $eligible
            ->where('outcome', GiftReconciliation::OUTCOME_UPDATE)
            ->map(function (GiftReconciliation $result) use ($approvals): array {
                $approval = $approvals->get($result->id);

                return [
                    'transaction_id' => $result->transaction_id,
                    'crm_record_id' => $approval->before_values['crm_record_id'] ?? null,
                    'before_hash' => $this->hashValues($approval->before_values),
                    'after_hash' => $this->hashValues($approval->after_values),
                ];
            })->values()->all();

        return [
            'batch_id' => $batchId,
            'export_fingerprint' => $fingerprint,
            'generated_at' => $generatedAt,
            'run' => [
                'id' => $run->id,
                'sequence' => $run->sequence,
                'input_fingerprint' => $run->input_fingerprint,
                'rule_version' => $run->rule_version,
            ],
            'profiles' => [
                'export' => self::PROFILE_VERSION,
                'mapping_profile_ids' => $mappingProfiles,
            ],
            'source_checksums' => $sourceChecksums,
            'counts' => [
                'creates' => $eligible->where('outcome', GiftReconciliation::OUTCOME_CREATE)->count(),
                'updates' => $eligible->where('outcome', GiftReconciliation::OUTCOME_UPDATE)->count(),
                'unchanged' => $results->where('outcome', GiftReconciliation::OUTCOME_UNCHANGED)->count(),
                'held' => $results->where('outcome', GiftReconciliation::OUTCOME_HELD)->count(),
                'approved_actions' => $approvals->count(),
            ],
            'files' => collect($fileChecksums)->map(fn (string $checksum): array => [
                'sha256' => $checksum,
            ])->all(),
            'updates' => $updates,
        ];
    }

    /** @param list<string> $headers @param list<list<mixed>> $rows */
    private function csv(array $headers, array $rows, bool $spreadsheetSafe = false): string
    {
        $stream = fopen('php://temp', 'w+');

        if ($stream === false) {
            throw new RuntimeException('A temporary export stream could not be opened.');
        }

        fputcsv($stream, $headers, ',', '"', '', "\n");

        foreach ($rows as $row) {
            $values = $spreadsheetSafe
                ? array_map($this->spreadsheetSafe(...), $row)
                : $row;
            fputcsv($stream, $values, ',', '"', '', "\n");
        }

        rewind($stream);
        $contents = stream_get_contents($stream);
        fclose($stream);

        if ($contents === false) {
            throw new RuntimeException('A generated CSV could not be read.');
        }

        return $contents;
    }

    /** @param array<string, string> $files */
    private function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'reconciliation-export-');

        if ($path === false) {
            throw new RuntimeException('A temporary ZIP path could not be created.');
        }

        try {
            $zip = new ZipArchive;

            if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The export ZIP could not be created.');
            }

            foreach ($files as $name => $contents) {
                $zip->addFromString($name, $contents);
                $zip->setMtimeName($name, 315532800);
            }

            if (! $zip->close()) {
                throw new RuntimeException('The export ZIP could not be finalized.');
            }

            $bytes = file_get_contents($path);

            if ($bytes === false) {
                throw new RuntimeException('The export ZIP could not be read.');
            }

            return $bytes;
        } finally {
            @unlink($path);
        }
    }

    private function requiredIdentifier(mixed $value, string $label): string
    {
        $text = $this->requiredText($value, $label);

        if (preg_match('/^[=+\-@]/', $text) === 1) {
            throw ValidationException::withMessages([
                'export' => "{$label} begins with a spreadsheet formula character and cannot be exported.",
            ]);
        }

        return $text;
    }

    private function requiredText(mixed $value, string $label): string
    {
        if (! is_string($value) || $value === '') {
            throw ValidationException::withMessages([
                'export' => "{$label} is required by the destination profile.",
            ]);
        }

        return $value;
    }

    private function minorToDecimal(mixed $value, string $label): string
    {
        if (! is_int($value)) {
            throw ValidationException::withMessages([
                'export' => "{$label} is not a valid minor-unit amount.",
            ]);
        }

        $negative = $value < 0;
        $absolute = abs($value);

        return ($negative ? '-' : '').intdiv($absolute, 100).'.'.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);
    }

    private function spreadsheetSafe(mixed $value): string
    {
        $text = is_string($value) ? $value : (string) $value;

        return preg_match('/^[=+\-@]/', $text) === 1 ? "'{$text}" : $text;
    }

    private function json(mixed $value): string
    {
        return json_encode($this->canonicalize($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function hashValues(mixed $value): string
    {
        return hash('sha256', $this->json($value));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (! array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return array_map($this->canonicalize(...), $value);
    }
}
