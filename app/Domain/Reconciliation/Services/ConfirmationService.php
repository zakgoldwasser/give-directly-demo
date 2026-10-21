<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\ConfirmationBatch;
use App\Models\ConfirmationResult;
use App\Models\ExportBatch;
use App\Models\GuestWorkspace;
use App\Services\Operations\ProcessingDeadline;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConfirmationService
{
    /** @var list<string> */
    private const REQUIRED_HEADERS = [
        'crm_record_id',
        'external_gift_key',
        'provider_transaction_id',
        'gross_amount',
        'refunded_amount',
        'currency',
        'gifted_at',
        'gift_status',
        'campaign_id',
    ];

    public function __construct(
        private readonly ReferenceFixtureCatalog $fixtures,
        private readonly CsvParserService $csv,
        private readonly ConfirmationComparator $comparator,
        private readonly ProcessingDeadline $deadline,
    ) {}

    public function confirm(GuestWorkspace $workspace, ExportBatch $export): ConfirmationBatch
    {
        $this->deadline->checkpoint();
        if ($export->guest_workspace_id !== $workspace->id) {
            abort(404);
        }

        $fixtures = $this->fixtures->forWorkspace($workspace);
        $snapshot = $fixtures->confirmationSnapshot();
        $parsed = $this->csv->parse($fixtures->confirmationSnapshotPath());
        $this->deadline->checkpoint();
        $this->validateSnapshot($parsed, $snapshot);
        $records = array_map($this->normalizeRecord(...), $parsed['rows']);
        $checksum = (string) $snapshot['sha256'];

        return DB::transaction(function () use ($workspace, $export, $snapshot, $records, $checksum): ConfirmationBatch {
            GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();
            $lockedExport = ExportBatch::query()
                ->whereKey($export->id)
                ->where('guest_workspace_id', $workspace->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = $lockedExport->confirmations()
                ->where('snapshot_checksum', $checksum)
                ->first();

            if ($existing !== null) {
                return $existing->load(['results' => fn ($query) => $query->orderBy('transaction_id')]);
            }

            $snapshotGeneratedAt = ($lockedExport->generated_at ?? now()->utc())->addSecond();

            $batch = $lockedExport->confirmations()->create([
                'guest_workspace_id' => $workspace->id,
                'snapshot_filename' => $snapshot['filename'],
                'snapshot_checksum' => $checksum,
                'snapshot_generated_at' => $snapshotGeneratedAt,
                'coverage_status' => ($snapshot['complete_snapshot'] ?? false)
                    ? ConfirmationBatch::COVERAGE_COMPLETE
                    : 'incomplete',
                'status' => ConfirmationBatch::STATUS_COMPLETED,
                'counts' => [],
                'completed_at' => $snapshotGeneratedAt,
            ]);
            $counts = [
                ConfirmationResult::OUTCOME_MATCHES => 0,
                ConfirmationResult::OUTCOME_MISMATCHES => 0,
                ConfirmationResult::OUTCOME_DUPLICATE => 0,
                ConfirmationResult::OUTCOME_CANNOT_VERIFY => 0,
            ];
            $approvals = $lockedExport->run->approvals()->orderBy('transaction_id')->get();

            foreach ($approvals as $approval) {
                $this->deadline->checkpoint();
                $comparison = $this->comparator->compare(
                    $approval,
                    $records,
                    $batch->coverage_status === ConfirmationBatch::COVERAGE_COMPLETE,
                );
                $counts[$comparison['outcome']]++;
                $batch->results()->create([
                    'reconciliation_approval_id' => $approval->id,
                    'transaction_id' => $approval->transaction_id,
                    'expected_action' => $approval->action,
                    ...$comparison,
                ]);
            }

            $batch->forceFill(['counts' => $counts])->save();
            $lockedExport->forceFill([
                'status' => ExportBatch::STATUS_CHECKED,
                'checked_at' => $snapshotGeneratedAt,
            ])->save();

            $this->deadline->checkpoint();

            return $batch->load(['results' => fn ($query) => $query->orderBy('transaction_id')]);
        });
    }

    /** @param array{headers: list<string>, rows: list<array<string, string>>, delimiter: string, total_rows: int} $parsed @param array<string, mixed> $snapshot */
    private function validateSnapshot(array $parsed, array $snapshot): void
    {
        $missing = array_values(array_diff(self::REQUIRED_HEADERS, $parsed['headers']));

        if ($missing !== []) {
            throw ValidationException::withMessages([
                'confirmation' => 'The confirmation snapshot is missing required columns: '.implode(', ', $missing).'.',
            ]);
        }

        if ($parsed['total_rows'] !== (int) $snapshot['expected_rows']) {
            throw ValidationException::withMessages([
                'confirmation' => 'The confirmation snapshot row count does not match its manifest.',
            ]);
        }
    }

    /** @param array<string, string> $row @return array<string, mixed> */
    private function normalizeRecord(array $row): array
    {
        $giftedAt = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $row['gifted_at']);

        if ($giftedAt === false || $giftedAt->format(DATE_ATOM) !== $row['gifted_at']) {
            throw ValidationException::withMessages([
                'confirmation' => 'The confirmation snapshot contains an invalid gift date.',
            ]);
        }

        return [
            'crm_record_id' => $this->required($row['crm_record_id'], 'CRM record ID'),
            'external_gift_key' => $this->nullable($row['external_gift_key']),
            'provider_transaction_id' => $this->nullable($row['provider_transaction_id']),
            'gross_amount_minor' => $this->money($row['gross_amount'], 'Gross amount'),
            'refunded_amount_minor' => $this->money($row['refunded_amount'], 'Refunded amount'),
            'currency' => $this->required($row['currency'], 'Currency'),
            'gifted_at' => $giftedAt->format(DATE_ATOM),
            'gift_status' => $this->required($row['gift_status'], 'Gift status'),
            'campaign_id' => $this->required($row['campaign_id'], 'Campaign ID'),
        ];
    }

    private function money(string $value, string $label): int
    {
        if (preg_match('/^(0|[1-9]\d*)\.(\d{2})$/', $value, $matches) !== 1) {
            throw ValidationException::withMessages([
                'confirmation' => "{$label} in the confirmation snapshot must use two decimal places.",
            ]);
        }

        return ((int) $matches[1] * 100) + (int) $matches[2];
    }

    private function required(string $value, string $label): string
    {
        if ($value === '') {
            throw ValidationException::withMessages([
                'confirmation' => "{$label} is required in the confirmation snapshot.",
            ]);
        }

        return $value;
    }

    private function nullable(string $value): ?string
    {
        return $value === '' ? null : $value;
    }
}
