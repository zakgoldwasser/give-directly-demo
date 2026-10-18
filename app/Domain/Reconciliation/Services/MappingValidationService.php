<?php

namespace App\Domain\Reconciliation\Services;

use App\Domain\Reconciliation\Exceptions\InvalidMappingException;
use App\Models\GuestWorkspace;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MappingProfile;
use DateTimeImmutable;
use DateTimeInterface;
use Exception;
use Illuminate\Support\Facades\DB;

class MappingValidationService
{
    public function __construct(
        private readonly CanonicalReportSchema $schemas,
    ) {}

    public function initialize(ImportBatch $batch): ImportBatch
    {
        $headers = $this->headers($batch);
        $fingerprint = $this->fingerprint($headers);

        $batch->forceFill(['schema_fingerprint' => $fingerprint])->save();

        $savedProfile = MappingProfile::query()
            ->where('guest_workspace_id', $batch->guest_workspace_id)
            ->where('report_type', $batch->report_type)
            ->where('header_fingerprint', $fingerprint)
            ->latest('version')
            ->first();

        if ($savedProfile !== null) {
            return $this->applyProfile($batch, $savedProfile);
        }

        $mapping = [];

        foreach (array_keys($this->schemas->fields($batch->report_type)) as $canonicalField) {
            if (in_array($canonicalField, $headers, true)) {
                $mapping[$canonicalField] = $canonicalField;
            }
        }

        $issues = $this->mappingIssues($batch->report_type, $headers, $mapping);

        if ($this->hasBlockingIssues($issues)) {
            $batch->forceFill([
                'mapping_profile_id' => null,
                'schema_issues' => $issues,
                'unmapped_headers' => $this->unmappedHeaders($headers, $mapping),
                'status' => ImportBatch::STATUS_MAPPING_REQUIRED,
                'valid_rows' => 0,
                'review_rows' => 0,
                'rejected_rows' => 0,
                'validated_at' => null,
            ])->save();

            return $batch->refresh();
        }

        return $this->applyProfile($batch, $this->createProfile($batch, $mapping));
    }

    /**
     * @param  array<string, string|null>  $submittedMapping
     *
     * @throws InvalidMappingException
     */
    public function saveMapping(ImportBatch $batch, array $submittedMapping): ImportBatch
    {
        $knownFields = $this->schemas->fields($batch->report_type);
        $mapping = [];

        foreach ($submittedMapping as $canonicalField => $sourceHeader) {
            if (! array_key_exists($canonicalField, $knownFields)) {
                continue;
            }

            if (is_string($sourceHeader) && $sourceHeader !== '') {
                $mapping[$canonicalField] = $sourceHeader;
            }
        }

        $headers = $this->headers($batch);
        $issues = $this->mappingIssues($batch->report_type, $headers, $mapping);

        if ($this->hasBlockingIssues($issues)) {
            throw new InvalidMappingException($issues);
        }

        return $this->applyProfile($batch, $this->createProfile($batch, $mapping));
    }

    /**
     * @param  list<string>  $headers
     */
    public function fingerprint(array $headers): string
    {
        $normalized = array_values($headers);
        sort($normalized, SORT_STRING);

        return hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
    }

    private function applyProfile(ImportBatch $batch, MappingProfile $profile): ImportBatch
    {
        $headers = $this->headers($batch);
        $issues = $this->mappingIssues($batch->report_type, $headers, $profile->mapping);

        if ($this->hasBlockingIssues($issues)) {
            throw new InvalidMappingException($issues);
        }

        $validRows = 0;
        $reviewRows = 0;
        $rejectedRows = 0;
        $updates = [];
        $now = now();

        foreach ($batch->rows()->orderBy('row_number')->get() as $row) {
            [$normalized, $rowIssues] = $this->normalizeRow(
                $batch->report_type,
                $row->raw_data,
                $profile->mapping,
            );

            $blocking = $this->hasBlockingIssues($rowIssues);
            $warnings = count(array_filter($rowIssues, fn (array $issue): bool => $issue['severity'] === 'warning'));

            if ($blocking) {
                $status = ImportRow::STATUS_INVALID;
                $rejectedRows++;
            } else {
                $status = ImportRow::STATUS_VALID;
                $validRows++;

                if ($warnings > 0) {
                    $reviewRows++;
                }
            }

            $updates[] = [
                'id' => $row->id,
                'import_batch_id' => $row->import_batch_id,
                'row_number' => $row->row_number,
                'raw_data' => json_encode($row->raw_data, JSON_THROW_ON_ERROR),
                'normalized_data' => json_encode($normalized, JSON_THROW_ON_ERROR),
                'status' => $status,
                'validation_issues' => $rowIssues === [] ? null : json_encode($rowIssues, JSON_THROW_ON_ERROR),
                'created_at' => $row->created_at,
                'updated_at' => $now,
            ];
        }

        DB::transaction(function () use ($batch, $profile, $issues, $headers, $updates, $validRows, $reviewRows, $rejectedRows, $now): void {
            foreach (array_chunk($updates, 200) as $chunk) {
                DB::table('import_rows')->upsert(
                    $chunk,
                    ['id'],
                    ['normalized_data', 'status', 'validation_issues', 'updated_at'],
                );
            }

            $batch->forceFill([
                'mapping_profile_id' => $profile->id,
                'schema_fingerprint' => $this->fingerprint($headers),
                'schema_issues' => $issues === [] ? null : $issues,
                'unmapped_headers' => $this->unmappedHeaders($headers, $profile->mapping),
                'status' => $rejectedRows === 0
                    ? ImportBatch::STATUS_VALIDATED
                    : ImportBatch::STATUS_VALIDATION_FAILED,
                'valid_rows' => $validRows,
                'review_rows' => $reviewRows,
                'rejected_rows' => $rejectedRows,
                'validated_at' => $now,
            ])->save();
        });

        return $batch->refresh()->load('mappingProfile');
    }

    /**
     * @param  array<string, string>  $mapping
     */
    private function createProfile(ImportBatch $batch, array $mapping): MappingProfile
    {
        return DB::transaction(function () use ($batch, $mapping): MappingProfile {
            GuestWorkspace::query()
                ->whereKey($batch->guest_workspace_id)
                ->lockForUpdate()
                ->firstOrFail();

            $latestVersion = MappingProfile::query()
                ->where('guest_workspace_id', $batch->guest_workspace_id)
                ->where('report_type', $batch->report_type)
                ->orderByDesc('version')
                ->value('version');

            return MappingProfile::query()->create([
                'guest_workspace_id' => $batch->guest_workspace_id,
                'report_type' => $batch->report_type,
                'adapter_profile' => $batch->context['profile_version'] ?? 'local_v1',
                'version' => ((int) $latestVersion) + 1,
                'schema_version' => (int) ($batch->context['schema_version'] ?? 1),
                'header_fingerprint' => $this->fingerprint($this->headers($batch)),
                'mapping' => $mapping,
                'settings' => [
                    'timezone' => $batch->context['timezone'] ?? 'UTC',
                    'date_format' => 'ISO-8601',
                    'money_format' => 'decimal_2',
                    'currency' => $batch->context['currency'] ?? 'GBP',
                    'status_mapping' => 'identity',
                ],
            ]);
        });
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, string>  $mapping
     * @return list<array{code: string, column: string|null, message: string, severity: string}>
     */
    private function mappingIssues(string $reportType, array $headers, array $mapping): array
    {
        $issues = [];
        $usedHeaders = [];

        foreach ($this->schemas->requiredFields($reportType) as $requiredField) {
            if (! isset($mapping[$requiredField]) || $mapping[$requiredField] === '') {
                $issues[] = $this->issue(
                    'MISSING_REQUIRED_MAPPING',
                    $requiredField,
                    "Choose a source column for {$requiredField}.",
                );
            }
        }

        foreach ($mapping as $canonicalField => $sourceHeader) {
            if (! array_key_exists($canonicalField, $this->schemas->fields($reportType))) {
                $issues[] = $this->issue('UNKNOWN_CANONICAL_FIELD', $canonicalField, "{$canonicalField} is not part of the {$reportType} schema.");

                continue;
            }

            if (! in_array($sourceHeader, $headers, true)) {
                $issues[] = $this->issue('UNKNOWN_SOURCE_COLUMN', $sourceHeader, "The source column {$sourceHeader} is not present in this file.");

                continue;
            }

            if (isset($usedHeaders[$sourceHeader])) {
                $issues[] = $this->issue('DUPLICATE_SOURCE_MAPPING', $sourceHeader, "The source column {$sourceHeader} is mapped more than once.");
            }

            $usedHeaders[$sourceHeader] = true;
        }

        foreach ($this->unmappedHeaders($headers, $mapping) as $unmappedHeader) {
            $issues[] = $this->issue(
                'UNMAPPED_COLUMN',
                $unmappedHeader,
                "The source column {$unmappedHeader} is retained as evidence but is not mapped.",
                'information',
            );
        }

        return $issues;
    }

    /**
     * @param  array<string, string>  $raw
     * @param  array<string, string>  $mapping
     * @return array{0: array<string, mixed>, 1: list<array{code: string, column: string|null, message: string, severity: string}>}
     */
    private function normalizeRow(string $reportType, array $raw, array $mapping): array
    {
        $normalized = [];
        $issues = [];

        foreach ($this->schemas->fields($reportType) as $canonicalField => $definition) {
            $sourceHeader = $mapping[$canonicalField] ?? null;

            if ($sourceHeader === null) {
                $normalized[$definition['normalized'] ?? $canonicalField] = null;

                continue;
            }

            $sourceValue = $raw[$sourceHeader] ?? '';
            [$value, $fieldIssues] = $this->normalizeValue($canonicalField, $sourceHeader, $sourceValue, $definition);
            $normalized[$definition['normalized'] ?? $canonicalField] = $value;
            array_push($issues, ...$fieldIssues);
        }

        array_push($issues, ...$this->crossFieldIssues($reportType, $normalized, $mapping));

        return [$normalized, $issues];
    }

    /**
     * @param  array{label: string, type: string, required: bool, normalized?: string, values?: list<string>}  $definition
     * @return array{0: mixed, 1: list<array{code: string, column: string|null, message: string, severity: string}>}
     */
    private function normalizeValue(string $canonicalField, string $sourceHeader, string $sourceValue, array $definition): array
    {
        $value = trim($sourceValue);

        if ($value === '') {
            if ($definition['required']) {
                return [null, [$this->issue('REQUIRED_VALUE', $sourceHeader, "{$definition['label']} is required.")]];
            }

            return [null, []];
        }

        return match ($definition['type']) {
            'text' => [$value, []],
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) !== false
                ? [mb_strtolower($value), []]
                : [null, [$this->issue('INVALID_EMAIL', $sourceHeader, "{$definition['label']} must be a valid email address.")]],
            'boolean' => $this->normalizeBoolean($value, $sourceHeader),
            'date' => $this->normalizeDate($value, $sourceHeader),
            'datetime' => $this->normalizeDateTime($value, $sourceHeader),
            'currency' => strtoupper($value) === 'GBP'
                ? ['GBP', []]
                : [strtoupper($value), [$this->issue('UNSUPPORTED_CURRENCY', $sourceHeader, 'The demo profile accepts GBP only.')]],
            'enum' => in_array($value, $definition['values'] ?? [], true)
                ? [$value, []]
                : [$value, [$this->issue(
                    $canonicalField === 'component_type' ? 'UNKNOWN_COMPONENT_TYPE' : 'UNKNOWN_STATUS',
                    $sourceHeader,
                    "{$value} is not a recognized {$canonicalField} value.",
                )]],
            'positive_integer' => $this->normalizePositiveInteger($value, $sourceHeader),
            'money', 'positive_money', 'non_negative_money' => $this->normalizeMoney($value, $sourceHeader, $definition['type']),
            default => [null, [$this->issue('UNSUPPORTED_FIELD_TYPE', $sourceHeader, "{$definition['label']} cannot be normalized.")]],
        };
    }

    /**
     * @return array{0: bool|null, 1: list<array{code: string, column: string|null, message: string, severity: string}>}
     */
    private function normalizeBoolean(string $value, string $column): array
    {
        return match (mb_strtolower($value)) {
            'true' => [true, []],
            'false' => [false, []],
            default => [null, [$this->issue('INVALID_BOOLEAN', $column, 'Use true or false for boolean values.')]],
        };
    }

    /**
     * @return array{0: string|null, 1: list<array{code: string, column: string|null, message: string, severity: string}>}
     */
    private function normalizeDate(string $value, string $column): array
    {
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = DateTimeImmutable::getLastErrors();

        if ($date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) || $date->format('Y-m-d') !== $value) {
            return [null, [$this->issue('INVALID_DATE', $column, 'Use an unambiguous YYYY-MM-DD date.')]];
        }

        return [$date->format('Y-m-d'), []];
    }

    /**
     * @return array{0: string|null, 1: list<array{code: string, column: string|null, message: string, severity: string}>}
     */
    private function normalizeDateTime(string $value, string $column): array
    {
        if (preg_match('/(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            return [null, [$this->issue('AMBIGUOUS_DATETIME', $column, 'Use an ISO-8601 timestamp with an explicit timezone offset.')]];
        }

        $normalizedInput = str_ends_with($value, 'Z')
            ? substr($value, 0, -1).'+00:00'
            : $value;

        try {
            $date = DateTimeImmutable::createFromFormat('!Y-m-d\TH:i:sP', $normalizedInput);
            $errors = DateTimeImmutable::getLastErrors();
        } catch (Exception) {
            return [null, [$this->issue('INVALID_DATETIME', $column, 'Use a valid ISO-8601 timestamp.')]];
        }

        if ($date === false
            || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $date->format(DateTimeInterface::ATOM) !== $normalizedInput) {
            return [null, [$this->issue('INVALID_DATETIME', $column, 'Use a valid ISO-8601 timestamp.')]];
        }

        return [$date->format(DateTimeInterface::ATOM), []];
    }

    /**
     * @return array{0: int|null, 1: list<array{code: string, column: string|null, message: string, severity: string}>}
     */
    private function normalizePositiveInteger(string $value, string $column): array
    {
        if (preg_match('/^[1-9]\d*$/', $value) !== 1 || strlen($value) > 9) {
            return [null, [$this->issue('INVALID_POSITIVE_INTEGER', $column, 'Use a positive whole number.')]];
        }

        return [(int) $value, []];
    }

    /**
     * @return array{0: int|null, 1: list<array{code: string, column: string|null, message: string, severity: string}>}
     */
    private function normalizeMoney(string $value, string $column, string $type): array
    {
        if (preg_match('/^([+-]?)(\d+)\.(\d{2})$/', $value, $matches) !== 1 || strlen($matches[2] ?? '') > 12) {
            return [null, [$this->issue('INVALID_MONEY', $column, 'Use an unambiguous decimal amount with exactly two decimal places.')]];
        }

        $minor = ((int) $matches[2] * 100) + (int) $matches[3];

        if ($matches[1] === '-') {
            $minor *= -1;
        }

        $maximum = (int) config('reconciliation.max_absolute_money_minor_units', 10_000_000_000);

        if (abs($minor) > $maximum) {
            return [null, [$this->issue('MONEY_OUT_OF_RANGE', $column, 'The amount exceeds the configured processing range.')]];
        }

        if ($type === 'positive_money' && $minor <= 0) {
            return [$minor, [$this->issue('AMOUNT_NOT_POSITIVE', $column, 'The amount must be greater than zero.')]];
        }

        if ($type === 'non_negative_money' && $minor < 0) {
            return [$minor, [$this->issue('AMOUNT_NEGATIVE', $column, 'The amount cannot be negative.')]];
        }

        return [$minor, []];
    }

    /**
     * @param  array<string, mixed>  $normalized
     * @param  array<string, string>  $mapping
     * @return list<array{code: string, column: string|null, message: string, severity: string}>
     */
    private function crossFieldIssues(string $reportType, array $normalized, array $mapping): array
    {
        $issues = [];

        if (in_array($reportType, ['donations', 'crm'], true)) {
            $gross = $normalized['gross_amount_minor'] ?? null;
            $refunded = $normalized['refunded_amount_minor'] ?? null;

            if (is_int($gross) && is_int($refunded) && $refunded > $gross) {
                $issues[] = $this->issue(
                    'REFUND_EXCEEDS_GROSS',
                    $mapping['refunded_amount'] ?? 'refunded_amount',
                    'Refunded amount cannot exceed the original gross amount.',
                );
            }
        }

        if ($reportType === 'donations') {
            $status = $normalized['status'] ?? null;
            $gross = $normalized['gross_amount_minor'] ?? null;
            $refunded = $normalized['refunded_amount_minor'] ?? null;

            if ($status === 'partially_refunded' && is_int($gross) && is_int($refunded) && ($refunded <= 0 || $refunded >= $gross)) {
                $issues[] = $this->issue('INCONSISTENT_REFUND_STATUS', $mapping['status'] ?? 'status', 'A partially refunded gift must have a returned amount between zero and gross.');
            }

            if ($status === 'refunded' && is_int($gross) && is_int($refunded) && $refunded !== $gross) {
                $issues[] = $this->issue('INCONSISTENT_REFUND_STATUS', $mapping['status'] ?? 'status', 'A refunded gift must have a returned amount equal to gross.');
            }
        }

        if ($reportType === 'payouts') {
            $type = $normalized['component_type'] ?? null;
            $transactionId = $normalized['transaction_id'] ?? null;
            $amount = $normalized['amount_signed_minor'] ?? null;

            if (in_array($type, ['gift_credit', 'refund_debit', 'reversal_debit'], true) && $transactionId === null) {
                $issues[] = $this->issue('TRANSACTION_ID_REQUIRED', $mapping['transaction_id'] ?? 'transaction_id', 'This component type requires a transaction ID.');
            }

            if ($type === 'gift_credit' && is_int($amount) && $amount <= 0) {
                $issues[] = $this->issue('INVALID_COMPONENT_SIGN', $mapping['amount_signed'] ?? 'amount_signed', 'Gift credits must be positive.');
            }

            if (in_array($type, ['fee', 'refund_debit', 'reversal_debit', 'adjustment_debit'], true) && is_int($amount) && $amount >= 0) {
                $issues[] = $this->issue('INVALID_COMPONENT_SIGN', $mapping['amount_signed'] ?? 'amount_signed', 'Debit components must be negative.');
            }

            if ($type === 'adjustment_credit' && is_int($amount) && $amount <= 0) {
                $issues[] = $this->issue('INVALID_COMPONENT_SIGN', $mapping['amount_signed'] ?? 'amount_signed', 'Credit adjustments must be positive.');
            }

            if (($normalized['payout_status'] ?? null) === 'sent' && ($normalized['payout_date'] ?? null) === null) {
                $issues[] = $this->issue('PAYOUT_DATE_REQUIRED', $mapping['payout_date'] ?? 'payout_date', 'Sent payouts require a payout date.');
            }
        }

        return $issues;
    }

    /**
     * @param  list<string>  $headers
     * @param  array<string, string>  $mapping
     * @return list<string>
     */
    private function unmappedHeaders(array $headers, array $mapping): array
    {
        return array_values(array_diff($headers, array_values($mapping)));
    }

    /**
     * @return list<string>
     */
    private function headers(ImportBatch $batch): array
    {
        $headers = $batch->parsing_config['headers'] ?? [];

        return is_array($headers) ? array_values(array_filter($headers, 'is_string')) : [];
    }

    /**
     * @param  list<array{code: string, column: string|null, message: string, severity: string}>  $issues
     */
    private function hasBlockingIssues(array $issues): bool
    {
        return count(array_filter($issues, fn (array $issue): bool => $issue['severity'] === 'blocking')) > 0;
    }

    /**
     * @return array{code: string, column: string|null, message: string, severity: string}
     */
    private function issue(string $code, ?string $column, string $message, string $severity = 'blocking'): array
    {
        return compact('code', 'column', 'message', 'severity');
    }
}
