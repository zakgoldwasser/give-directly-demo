<?php

namespace App\Domain\Reconciliation\Services;

use JsonException;
use RuntimeException;

class ReferenceFixtureCatalog
{
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
        $path = config('reconciliation.reference_fixture_manifest');

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
}
