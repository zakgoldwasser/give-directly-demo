<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\CanonicalRecord;
use App\Models\CanonicalRecordVersion;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\SourceOccurrence;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CanonicalRecordMaterializer
{
    private const UNKNOWN_REVISION_AT = '1970-01-01T00:00:00+00:00';

    public function __construct(
        private readonly ScopedRecordIdentity $identities,
    ) {}

    /**
     * Rebuild current resolution state from immutable validated rows. Canonical
     * records, versions, and mapping-specific source occurrences remain append-only.
     */
    public function rebuildWorkspace(GuestWorkspace $workspace): void
    {
        DB::transaction(function () use ($workspace): void {
            GuestWorkspace::query()->whereKey($workspace->id)->lockForUpdate()->firstOrFail();

            $batches = $workspace->importBatches()
                ->with(['rows' => fn ($query) => $query->where('status', ImportRow::STATUS_VALID)->orderBy('row_number')])
                ->orderBy('created_at')
                ->orderBy('id')
                ->get();

            $entries = $this->entries($batches);
            $groups = $this->groupEntries($entries);

            $now = now();
            $existingRecords = CanonicalRecord::query()
                ->where('guest_workspace_id', $workspace->id)
                ->get()
                ->keyBy(fn (CanonicalRecord $record): string => $record->record_type.'|'.$record->logical_key_hash);
            $recordRows = [];
            $newRecordRows = [];

            foreach ($groups as $groupKey => &$group) {
                $record = $existingRecords->get($groupKey);
                $recordId = $record?->id ?? (string) Str::uuid();
                $group['record_id'] = $recordId;
                $recordRows[$groupKey] = [
                    'id' => $recordId,
                    'guest_workspace_id' => $workspace->id,
                    'record_type' => $group['record_type'],
                    'logical_key_hash' => $group['logical_key_hash'],
                    'identity' => json_encode($group['identity'], JSON_THROW_ON_ERROR),
                    'resolution_status' => CanonicalRecord::RESOLUTION_INACTIVE,
                    'active_version_id' => null,
                    'created_at' => $record?->created_at ?? $now,
                    'updated_at' => $now,
                ];

                if ($record === null) {
                    $newRecordRows[] = $recordRows[$groupKey];
                }
            }
            unset($group);

            foreach (array_chunk($newRecordRows, 200) as $chunk) {
                DB::table('canonical_records')->insert($chunk);
            }

            $recordIds = array_column($recordRows, 'id');
            $existingVersions = $this->existingVersions($recordIds);
            $versionRows = [];
            $newVersionRows = [];
            $occurrenceRows = [];
            $batchCounts = $this->emptyBatchCounts($batches);

            foreach ($groups as $groupKey => $group) {
                $recordId = $group['record_id'];
                $byRevision = [];

                foreach ($group['entries'] as $entry) {
                    $byRevision[$entry['revision_key']][$entry['content_fingerprint']][] = $entry;
                }

                ksort($byRevision, SORT_STRING);
                $latestRevision = array_key_last($byRevision);
                $latestConflicts = count($byRevision[$latestRevision]) > 1;
                $activeVersionId = null;

                foreach ($byRevision as $revisionKey => $contentGroups) {
                    ksort($contentGroups, SORT_STRING);
                    $isLatest = $revisionKey === $latestRevision;
                    $isConflict = $isLatest && $latestConflicts;

                    foreach ($contentGroups as $contentFingerprint => $matchingEntries) {
                        usort($matchingEntries, $this->compareEntries(...));
                        $representative = $matchingEntries[0];
                        $versionKey = $recordId.'|'.$revisionKey.'|'.$contentFingerprint;
                        $existingVersion = $existingVersions->get($versionKey);
                        $versionId = $existingVersion?->id ?? (string) Str::uuid();
                        $resolutionState = $isConflict
                            ? CanonicalRecordVersion::STATE_CONFLICTING
                            : ($isLatest ? CanonicalRecordVersion::STATE_ACTIVE : CanonicalRecordVersion::STATE_SUPERSEDED);

                        if ($resolutionState === CanonicalRecordVersion::STATE_ACTIVE && $activeVersionId === null) {
                            $activeVersionId = $versionId;
                        }

                        $versionRow = [
                            'id' => $versionId,
                            'canonical_record_id' => $recordId,
                            'source_import_batch_id' => $existingVersion?->source_import_batch_id ?? $representative['import_batch_id'],
                            'source_import_row_id' => $existingVersion?->source_import_row_id ?? $representative['import_row_id'],
                            'revision_at' => $representative['revision_at'],
                            'revision_source' => $existingVersion?->revision_source ?? $representative['revision_source'],
                            'content_fingerprint' => $contentFingerprint,
                            'normalized_data' => json_encode($representative['normalized_data'], JSON_THROW_ON_ERROR),
                            'resolution_state' => $resolutionState,
                            'created_at' => $existingVersion?->created_at ?? $now,
                            'updated_at' => $now,
                        ];
                        $versionRows[] = $versionRow;

                        if ($existingVersion === null) {
                            $newVersionRows[] = $versionRow;
                        }

                        foreach ($matchingEntries as $index => $entry) {
                            $kind = $this->occurrenceKind($isLatest, $isConflict, $index);
                            $occurrenceRows[] = [
                                'id' => (string) Str::uuid(),
                                'guest_workspace_id' => $workspace->id,
                                'import_batch_id' => $entry['import_batch_id'],
                                'import_row_id' => $entry['import_row_id'],
                                'mapping_profile_id' => $entry['mapping_profile_id'],
                                'canonical_record_id' => $recordId,
                                'canonical_record_version_id' => $versionId,
                                'occurrence_kind' => $kind,
                                'created_at' => $now,
                                'updated_at' => $now,
                            ];

                            $this->incrementCounts($batchCounts[$entry['import_batch_id']], $kind);
                        }
                    }
                }

                $recordRows[$groupKey]['resolution_status'] = $latestConflicts
                    ? CanonicalRecord::RESOLUTION_CONFLICTING
                    : CanonicalRecord::RESOLUTION_ACTIVE;
                $recordRows[$groupKey]['active_version_id'] = $activeVersionId;
            }

            CanonicalRecord::query()
                ->where('guest_workspace_id', $workspace->id)
                ->update([
                    'resolution_status' => CanonicalRecord::RESOLUTION_INACTIVE,
                    'active_version_id' => null,
                    'updated_at' => $now,
                ]);

            CanonicalRecordVersion::query()
                ->whereHas('record', fn ($query) => $query->where('guest_workspace_id', $workspace->id))
                ->update([
                    'resolution_state' => CanonicalRecordVersion::STATE_SUPERSEDED,
                    'updated_at' => $now,
                ]);

            foreach (array_chunk($newVersionRows, 200) as $chunk) {
                DB::table('canonical_record_versions')->insert($chunk);
            }

            foreach (array_chunk($versionRows, 200) as $chunk) {
                DB::table('canonical_record_versions')->upsert(
                    $chunk,
                    ['id'],
                    ['resolution_state', 'updated_at'],
                );
            }

            foreach (array_chunk(array_values($recordRows), 200) as $chunk) {
                DB::table('canonical_records')->upsert(
                    $chunk,
                    ['id'],
                    ['identity', 'resolution_status', 'active_version_id', 'updated_at'],
                );
            }

            foreach (array_chunk($occurrenceRows, 200) as $chunk) {
                DB::table('source_occurrences')->upsert(
                    $chunk,
                    ['import_row_id', 'mapping_profile_id'],
                    ['canonical_record_id', 'canonical_record_version_id', 'occurrence_kind', 'updated_at'],
                );
            }

            foreach ($batches as $batch) {
                $batch->forceFill([
                    ...$batchCounts[$batch->id],
                    'materialized_at' => $batch->status === ImportBatch::STATUS_VALIDATED ? $now : null,
                ])->save();
            }
        });
    }

    /**
     * @param  Collection<int, ImportBatch>  $batches
     * @return list<array<string, mixed>>
     */
    private function entries(Collection $batches): array
    {
        $entries = [];

        foreach ($batches->where('status', ImportBatch::STATUS_VALIDATED) as $batch) {
            foreach ($batch->rows as $row) {
                $entries[] = $this->entry($batch, $row);
            }
        }

        return $entries;
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return array<string, array<string, mixed>>
     */
    private function groupEntries(array $entries): array
    {
        $groups = [];

        foreach ($entries as $entry) {
            $groupKey = $entry['record_type'].'|'.$entry['logical_key_hash'];
            $groups[$groupKey]['identity'] = $entry['identity'];
            $groups[$groupKey]['record_type'] = $entry['record_type'];
            $groups[$groupKey]['logical_key_hash'] = $entry['logical_key_hash'];
            $groups[$groupKey]['entries'][] = $entry;
        }

        ksort($groups, SORT_STRING);

        return $groups;
    }

    /**
     * @param  list<string>  $recordIds
     * @return Collection<string, CanonicalRecordVersion>
     */
    private function existingVersions(array $recordIds): Collection
    {
        if ($recordIds === []) {
            return collect();
        }

        return CanonicalRecordVersion::query()
            ->whereIn('canonical_record_id', $recordIds)
            ->get()
            ->keyBy(fn (CanonicalRecordVersion $version): string => implode('|', [
                $version->canonical_record_id,
                $version->revision_at->utc()->format('Y-m-d\TH:i:s.uP'),
                $version->content_fingerprint,
            ]));
    }

    /**
     * @param  Collection<int, ImportBatch>  $batches
     * @return array<string, array{materialized_rows: int, duplicate_rows: int, stale_rows: int, conflicting_rows: int}>
     */
    private function emptyBatchCounts(Collection $batches): array
    {
        $counts = [];

        foreach ($batches as $batch) {
            $counts[$batch->id] = [
                'materialized_rows' => 0,
                'duplicate_rows' => 0,
                'stale_rows' => 0,
                'conflicting_rows' => 0,
            ];
        }

        return $counts;
    }

    /**
     * @param  array{materialized_rows: int, duplicate_rows: int, stale_rows: int, conflicting_rows: int}  $counts
     */
    private function incrementCounts(array &$counts, string $kind): void
    {
        $counts['materialized_rows']++;

        if (in_array($kind, [SourceOccurrence::KIND_IDENTICAL_DUPLICATE, SourceOccurrence::KIND_CONFLICT_DUPLICATE], true)) {
            $counts['duplicate_rows']++;
        }

        if ($kind === SourceOccurrence::KIND_STALE_REVISION) {
            $counts['stale_rows']++;
        }

        if (in_array($kind, [SourceOccurrence::KIND_CONFLICT, SourceOccurrence::KIND_CONFLICT_DUPLICATE], true)) {
            $counts['conflicting_rows']++;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(ImportBatch $batch, ImportRow $row): array
    {
        $normalized = $row->normalized_data;
        $context = $batch->context ?? [];
        $organizationId = (string) ($context['organization_id'] ?? 'local_organization');
        $providerId = (string) ($context['provider_id'] ?? 'local_provider');
        $accountId = (string) ($context['account_id'] ?? 'local_account');

        [$recordType, $identityParts, $identity] = match ($batch->report_type) {
            'donations' => [
                CanonicalRecord::TYPE_GIFT,
                [$organizationId, $providerId, $accountId, (string) $normalized['transaction_id']],
                [
                    'organization_id' => $organizationId,
                    'provider_id' => $providerId,
                    'account_id' => $accountId,
                    'transaction_id' => (string) $normalized['transaction_id'],
                ],
            ],
            'payouts' => [
                CanonicalRecord::TYPE_PAYOUT_COMPONENT,
                [$organizationId, $providerId, $accountId, (string) $normalized['component_id']],
                [
                    'organization_id' => $organizationId,
                    'provider_id' => $providerId,
                    'account_id' => $accountId,
                    'component_id' => (string) $normalized['component_id'],
                ],
            ],
            'crm' => [
                CanonicalRecord::TYPE_CRM_RECORD,
                [$organizationId, (string) $normalized['crm_record_id']],
                [
                    'organization_id' => $organizationId,
                    'crm_record_id' => (string) $normalized['crm_record_id'],
                ],
            ],
        };

        if ($recordType === CanonicalRecord::TYPE_GIFT) {
            $normalized['external_gift_key'] = $this->identities->externalGiftKey(
                $organizationId,
                $providerId,
                $accountId,
                (string) $normalized['transaction_id'],
            );
        }

        [$revisionAt, $revisionSource] = $this->revision($batch, $normalized);
        ksort($normalized, SORT_STRING);

        return [
            'record_type' => $recordType,
            'logical_key_hash' => $this->identities->hash([$recordType, ...$identityParts]),
            'identity' => $identity,
            'normalized_data' => $normalized,
            'content_fingerprint' => hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'revision_at' => $revisionAt,
            'revision_key' => $revisionAt->format('Y-m-d\TH:i:s.uP'),
            'revision_source' => $revisionSource,
            'import_batch_id' => $batch->id,
            'import_row_id' => $row->id,
            'mapping_profile_id' => $batch->mapping_profile_id,
            'import_created_at' => $batch->created_at->format('Y-m-d\TH:i:s.uP'),
            'row_number' => $row->row_number,
        ];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @return array{0: CarbonImmutable, 1: string}
     */
    private function revision(ImportBatch $batch, array $normalized): array
    {
        if (isset($normalized['source_updated_at']) && is_string($normalized['source_updated_at'])) {
            return [CarbonImmutable::parse($normalized['source_updated_at'])->utc(), 'source_updated_at'];
        }

        $generatedAt = $batch->context['generated_at'] ?? null;

        if (is_string($generatedAt) && $generatedAt !== '') {
            return [CarbonImmutable::parse($generatedAt)->utc(), 'generated_at'];
        }

        return [CarbonImmutable::parse(self::UNKNOWN_REVISION_AT), 'missing'];
    }

    private function occurrenceKind(bool $isLatest, bool $isConflict, int $index): string
    {
        if (! $isLatest) {
            return SourceOccurrence::KIND_STALE_REVISION;
        }

        if ($isConflict) {
            return $index === 0
                ? SourceOccurrence::KIND_CONFLICT
                : SourceOccurrence::KIND_CONFLICT_DUPLICATE;
        }

        return $index === 0
            ? SourceOccurrence::KIND_PRIMARY
            : SourceOccurrence::KIND_IDENTICAL_DUPLICATE;
    }

    /**
     * @param  array<string, mixed>  $left
     * @param  array<string, mixed>  $right
     */
    private function compareEntries(array $left, array $right): int
    {
        return [$left['import_created_at'], $left['import_batch_id'], $left['row_number']]
            <=> [$right['import_created_at'], $right['import_batch_id'], $right['row_number']];
    }
}
