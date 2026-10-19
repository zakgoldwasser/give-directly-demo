<?php

namespace App\Support\Benchmark;

use RuntimeException;

final class ReconciliationBenchmarkDataset
{
    public const DONATION_ROWS = 400;

    public const PAYOUT_ROWS = 300;

    public const CRM_ROWS = 300;

    public const TOTAL_ROWS = self::DONATION_ROWS + self::PAYOUT_ROWS + self::CRM_ROWS;

    /**
     * @return array{donations: string, payouts: string, crm: string}
     */
    public function write(string $directory): array
    {
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('The benchmark directory could not be created.');
        }

        $paths = [
            'donations' => $directory.DIRECTORY_SEPARATOR.'donations.csv',
            'payouts' => $directory.DIRECTORY_SEPARATOR.'payouts.csv',
            'crm' => $directory.DIRECTORY_SEPARATOR.'crm.csv',
        ];

        $this->writeCsv($paths['donations'], [
            'transaction_id', 'gifted_at', 'gross_amount', 'currency', 'status',
            'refunded_amount', 'campaign_code', 'donor_ref', 'donor_email',
            'anonymous', 'source_updated_at',
        ], $this->donations());

        $this->writeCsv($paths['payouts'], [
            'component_id', 'payout_id', 'component_type', 'amount_signed',
            'currency', 'transaction_id', 'reported_net_amount',
            'expected_item_count', 'payout_status', 'payout_date', 'source_updated_at',
        ], $this->payouts());

        $this->writeCsv($paths['crm'], [
            'crm_record_id', 'external_gift_key', 'provider_transaction_id',
            'gross_amount', 'refunded_amount', 'currency', 'gifted_at',
            'gift_status', 'campaign_id', 'donor_ref', 'donor_email',
        ], $this->crm());

        return $paths;
    }

    /** @return iterable<int, list<string>> */
    private function donations(): iterable
    {
        for ($index = 1; $index <= self::DONATION_ROWS; $index++) {
            $id = sprintf('BENCH-D%04d', $index);

            yield [
                $id,
                '2026-09-10T10:00:00+01:00',
                '10.00',
                'GBP',
                'paid',
                '0.00',
                'GENERAL',
                sprintf('BENCH-DONOR-%04d', $index),
                sprintf('benchmark-%04d@example.test', $index),
                'false',
                '2026-09-10T10:01:00+01:00',
            ];
        }
    }

    /** @return iterable<int, list<string>> */
    private function payouts(): iterable
    {
        for ($index = 1; $index <= self::PAYOUT_ROWS; $index++) {
            yield [
                sprintf('BENCH-C%04d', $index),
                'BENCH-P001',
                'gift_credit',
                '10.00',
                'GBP',
                sprintf('BENCH-D%04d', $index),
                '3000.00',
                (string) self::PAYOUT_ROWS,
                'sent',
                '2026-09-30',
                '2026-09-30T18:00:00+01:00',
            ];
        }
    }

    /** @return iterable<int, list<string>> */
    private function crm(): iterable
    {
        for ($index = 1; $index <= self::CRM_ROWS; $index++) {
            yield [
                sprintf('BENCH-CRM%04d', $index),
                '',
                sprintf('BENCH-D%04d', $index),
                '10.00',
                '0.00',
                'GBP',
                '2026-09-10T10:00:00+01:00',
                'paid',
                'CAMPAIGN-GENERAL',
                sprintf('BENCH-DONOR-%04d', $index),
                sprintf('benchmark-%04d@example.test', $index),
            ];
        }
    }

    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string>>  $rows
     */
    private function writeCsv(string $path, array $headers, iterable $rows): void
    {
        $handle = fopen($path, 'wb');

        if ($handle === false) {
            throw new RuntimeException("The benchmark file [{$path}] could not be created.");
        }

        try {
            fputcsv($handle, $headers, escape: '');

            foreach ($rows as $row) {
                fputcsv($handle, $row, escape: '');
            }
        } finally {
            fclose($handle);
        }
    }
}
