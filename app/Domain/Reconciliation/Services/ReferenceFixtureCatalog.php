<?php

namespace App\Domain\Reconciliation\Services;

use App\Models\GuestWorkspace;
use JsonException;
use RuntimeException;

class ReferenceFixtureCatalog
{
    public function __construct(private readonly string $scenarioId = 'reference_v1') {}

    /** @return array<string, array<string, string>> */
    public function choices(): array
    {
        return config('reconciliation.sample_scenarios', []);
    }

    /** @return array<string, array<string, array<string, mixed>>> */
    public function previews(CsvParserService $parser): array
    {
        $previews = [];

        foreach (array_keys($this->choices()) as $scenarioId) {
            $catalog = $this->forScenario($scenarioId);
            $notes = $catalog->scenario()['preview_notes'] ?? [];

            foreach (['donations' => 'transaction_id', 'payouts' => 'component_id', 'crm' => 'crm_record_id'] as $type => $key) {
                $parsed = $parser->parse($catalog->path($type));
                $previews[$scenarioId][$type] = [
                    'filename' => $catalog->report($type)['filename'],
                    'headers' => $parsed['headers'],
                    'rows' => array_map(fn (array $row, int $index): array => [
                        'number' => $index + 1,
                        'values' => $row,
                        'note' => $notes[$type][$row[$key]] ?? null,
                    ], $parsed['rows'], array_keys($parsed['rows'])),
                ];
            }
        }

        return $previews;
    }

    public function forScenario(string $scenarioId): self
    {
        if (! array_key_exists($scenarioId, $this->choices())) {
            throw new RuntimeException('Unknown sample scenario.');
        }

        return new self($scenarioId);
    }

    public function forWorkspace(GuestWorkspace $workspace): self
    {
        $batch = $workspace->importBatches()->where('report_type', 'donations')->oldest()->first();
        $scenarioId = $batch?->context['scenario_id'] ?? 'reference_v1';

        return $this->forScenario(array_key_exists($scenarioId, $this->choices()) ? $scenarioId : 'reference_v1');
    }

    /** @return array<string, mixed>|null */
    public function contextForUpload(string $reportType, string $checksum): ?array
    {
        foreach (array_keys($this->choices()) as $scenarioId) {
            $context = $this->forScenario($scenarioId)->contextFor($reportType, $checksum);

            if ($context !== null) {
                return $context;
            }
        }

        return null;
    }

    /**
     * @var array<string, mixed>|null
     */
    private ?array $manifest = null;

    /**
     * @return array<string, mixed>
     */
    public function scenario(): array
    {
        if ($this->manifest !== null) {
            return $this->manifest;
        }

        $path = $this->manifestPath();
        $contents = file_get_contents($path);

        if ($contents === false) {
            throw new RuntimeException('The reference fixture manifest could not be read.');
        }

        try {
            $manifest = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The reference fixture manifest is invalid JSON.', previous: $exception);
        }

        if (! is_array($manifest) || ! isset($manifest['reports']) || ! is_array($manifest['reports'])) {
            throw new RuntimeException('The reference fixture manifest has no report catalog.');
        }

        foreach (['donations', 'payouts', 'crm'] as $reportType) {
            $this->validateReport($manifest, $reportType);
        }

        $this->validateConfirmationSnapshot($manifest);

        return $this->manifest = $manifest;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function reports(): array
    {
        /** @var array<string, array<string, mixed>> $reports */
        $reports = $this->scenario()['reports'];

        return $reports;
    }

    /**
     * @return array<string, mixed>
     */
    public function report(string $reportType): array
    {
        $report = $this->reports()[$reportType] ?? null;

        if (! is_array($report)) {
            throw new RuntimeException("Unknown reference report type [{$reportType}].");
        }

        return $report;
    }

    public function path(string $reportType): string
    {
        $filename = $this->report($reportType)['filename'];
        $path = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.$filename;
        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath)) {
            throw new RuntimeException("The reference {$reportType} fixture is missing.");
        }

        return $realPath;
    }

    public function isApproved(string $reportType, string $checksum): bool
    {
        $report = $this->reports()[$reportType] ?? null;

        return is_array($report)
            && isset($report['sha256'])
            && hash_equals((string) $report['sha256'], strtolower($checksum));
    }

    /** @return array<string, mixed> */
    public function confirmationSnapshot(): array
    {
        $snapshot = $this->scenario()['confirmation_snapshot'] ?? null;

        if (! is_array($snapshot)) {
            throw new RuntimeException('The reference confirmation snapshot is missing.');
        }

        return $snapshot;
    }

    public function confirmationSnapshotPath(): string
    {
        $filename = $this->confirmationSnapshot()['filename'];
        $path = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.$filename;
        $realPath = realpath($path);

        if ($realPath === false || ! is_file($realPath)) {
            throw new RuntimeException('The reference confirmation snapshot file is missing.');
        }

        return $realPath;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function contextFor(string $reportType, string $checksum): ?array
    {
        if (! $this->isApproved($reportType, $checksum)) {
            return null;
        }

        $scenario = $this->scenario();
        $report = $this->report($reportType);

        return [
            'scenario_id' => $scenario['scenario_id'],
            'organization_id' => $scenario['organization_id'],
            'provider_id' => $scenario['provider_id'],
            'account_id' => $scenario['account_id'],
            'currency' => $scenario['currency'],
            ...$report['context'],
        ];
    }

    private function manifestPath(): string
    {
        $path = $this->scenarioId === 'reference_v1'
            ? config('reconciliation.reference_fixture_manifest')
            : ($this->choices()[$this->scenarioId]['manifest'] ?? null);

        if (! is_string($path) || ! is_file($path)) {
            throw new RuntimeException('The reference fixture manifest is missing.');
        }

        return $path;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function validateReport(array $manifest, string $reportType): void
    {
        $report = $manifest['reports'][$reportType] ?? null;

        if (! is_array($report)
            || ! isset($report['filename'], $report['sha256'], $report['expected_rows'], $report['context'])
            || ! is_array($report['context'])) {
            throw new RuntimeException("The reference {$reportType} report entry is incomplete.");
        }

        $path = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.$report['filename'];
        $actualChecksum = is_file($path) ? hash_file('sha256', $path) : false;

        if ($actualChecksum === false || ! hash_equals((string) $report['sha256'], $actualChecksum)) {
            throw new RuntimeException("The reference {$reportType} fixture checksum does not match its manifest.");
        }
    }

    /** @param array<string, mixed> $manifest */
    private function validateConfirmationSnapshot(array $manifest): void
    {
        $snapshot = $manifest['confirmation_snapshot'] ?? null;

        if (! is_array($snapshot)
            || ! isset($snapshot['filename'], $snapshot['sha256'], $snapshot['expected_rows'], $snapshot['expected'])
            || ! is_array($snapshot['expected'])
            || ! isset($snapshot['verification_outcomes'])
            || ! is_array($snapshot['verification_outcomes'])
            || array_diff(['create', 'update', 'unchanged', 'held'], array_keys($snapshot['verification_outcomes'])) !== []) {
            throw new RuntimeException('The reference confirmation snapshot entry is incomplete.');
        }

        $path = dirname($this->manifestPath()).DIRECTORY_SEPARATOR.$snapshot['filename'];
        $actualChecksum = is_file($path) ? hash_file('sha256', $path) : false;

        if ($actualChecksum === false || ! hash_equals((string) $snapshot['sha256'], $actualChecksum)) {
            throw new RuntimeException('The reference confirmation snapshot checksum does not match its manifest.');
        }
    }
}
